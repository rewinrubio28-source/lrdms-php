<?php
/** Content checks complement extension allowlists; not a malware scanner. */
function document_upload_error(string $path, string $name, bool $ocrOnly = false): ?string {
    if (!is_file($path) || !is_readable($path)) return 'Uploaded file is unavailable.';
    $size = filesize($path);
    if (!$size || $size > 25*1024*1024) return 'Choose a nonempty file up to 25 MB.';
    $ext = strtolower(pathinfo($name,PATHINFO_EXTENSION));
    $allowed = ['pdf'=>['application/pdf'],'png'=>['image/png'],'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'gif'=>['image/gif'],'webp'=>['image/webp']];
    if (!$ocrOnly) $allowed += ['txt'=>['text/plain'],'doc'=>['application/msword','application/x-ole-storage','application/CDFV2'],'docx'=>['application/zip','application/vnd.openxmlformats-officedocument.wordprocessingml.document']];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!isset($allowed[$ext]) || !in_array($mime,$allowed[$ext],true)) return 'File contents do not match a supported document type.';
    if (str_starts_with($mime,'image/')) {
        $image = @getimagesize($path);
        if (!$image || $image[0] < 1 || $image[1] < 1 || $image[0]*$image[1] > 40000000) return 'Choose a valid image up to 40 megapixels.';
    }
    if ($ext === 'docx') {
        if (!class_exists('ZipArchive')) return 'Word document validation is unavailable.';
        $zip = new ZipArchive();
        if ($zip->open($path)!==true) return 'Invalid Word document.';
        try {
            if ($zip->numFiles>3000 || $zip->locateName('[Content_Types].xml')===false || $zip->locateName('word/document.xml')===false) return 'Invalid Word document.';
            $total = 0;
            for ($i=0;$i<$zip->numFiles;$i++) {
                $entry = $zip->statIndex($i);
                if (!$entry || preg_match('~(^/|(^|/)\.\.(/|$)|vbaProject\.bin$)~i',$entry['name'])) return 'Unsafe Word document archive.';
                $total += $entry['size'];
                if ($total>50*1024*1024) return 'Word document contents exceed the 50 MB limit.';
            }
        } finally { $zip->close(); }
    }
    return null;
}
