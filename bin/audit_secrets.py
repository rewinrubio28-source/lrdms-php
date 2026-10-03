"""Read-only heuristic scan of tracked working files and reachable Git blobs.
Reports locations/rule names only, never secret values. Not a full secret scanner.
"""
import json
import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
RULES = {
    'private-key': re.compile(r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----'),
    'github-token': re.compile(r'\b(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{40,})\b'),
    'aws-access-key': re.compile(r'\b(?:AKIA|ASIA)[A-Z0-9]{16}\b'),
    'google-api-key': re.compile(r'\bAIza[A-Za-z0-9_-]{35}\b'),
    'literal-credential': re.compile(r'''(?i)(?:password|api[_-]?key|secret[_-]?key|smtp_password|db_pass)\s*['"]?\s*(?:=>|=|,)\s*['"]([^'"\r\n]{12,})['"]'''),
}

def scan(data, location):
    if b'\0' in data or len(data)>5_000_000:
        return []
    text=data.decode('utf-8', errors='replace')
    found=[]
    for rule, pattern in RULES.items():
        for match in pattern.finditer(text):
            value=match.group(1) if rule=='literal-credential' else match.group(0)
            if rule=='literal-credential' and (any(x in value.lower() for x in ['example','change-this','your-','test','dummy','placeholder']) or re.search(r'[\s<>$()]',value)):
                continue
            found.append({'location':location,'line':text.count('\n',0,match.start())+1,'rule':rule})
    return found

def git(*args):
    return subprocess.check_output(['git',*args],cwd=ROOT)

def main():
    findings=[]
    paths=git('ls-files','-z').decode().split('\0')
    tracked=0
    for name in filter(None,paths):
        path=ROOT/name
        if path.is_file():
            findings+=scan(path.read_bytes(),'working:'+name)
            tracked+=1
    objects={}
    for line in git('rev-list','--objects','--all').decode().splitlines():
        parts=line.split(' ',1)
        if len(parts)==2: objects[parts[0]]=parts[1]
    # Batch object metadata; scan each unique historical blob once.
    meta=subprocess.run(['git','cat-file','--batch-check'],input=('\n'.join(objects)+'\n').encode(),stdout=subprocess.PIPE,check=True,cwd=ROOT)
    blobs=0
    for line in meta.stdout.decode().splitlines():
        sha,kind,size=line.split()
        if kind!='blob' or int(size)>5_000_000: continue
        findings+=scan(git('cat-file','blob',sha),'history:'+sha[:12]+':'+objects[sha])
        blobs+=1
    print(json.dumps({'tracked_files':tracked,'historical_blobs':blobs,'findings':findings,'limits':'Heuristic rules only; binary/over-5MB files and ignored local secrets excluded. Findings require review; values deliberately omitted.'},indent=2))

if __name__=='__main__': main()
