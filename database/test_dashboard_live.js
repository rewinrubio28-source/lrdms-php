'use strict';
// Browser-independent interaction tests; no installed JS packages required.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const callbacks = {};
const form = { values: {from:'2026-01-01',to:'2026-03-31',type:'All',status:'All'}, addEventListener: (name, cb) => callbacks[name] = cb };
const status = {textContent:''};
const region = {innerHTML:'original',setAttribute(){},removeAttribute(){},contains(){return false;},replaceChildren(){this.innerHTML='';},querySelectorAll(){return [];}};
const links = ['csv','xlsx','pdf'].map(format => ({dataset:{dashboardExport:format},href:'initial',removeAttribute(name){delete this[name];}}));
const recordLink = {href:'initial',removeAttribute(name){delete this[name];}};
const refreshButton = {addEventListener: (_,cb) => callbacks.refresh = cb};
const elements = {'dashboard-filters':form,'dashboard-live':region,'dashboard-refresh-status':status,'dashboard-refresh':refreshButton,'dashboard-records-link':recordLink};
const document = {hidden:false,activeElement:null,getElementById:id=>elements[id],querySelectorAll:selector=>selector.includes('#dashboard-records-link')?[...links,recordLink]:links,addEventListener:(name,cb)=>callbacks[name]=cb};
const calls = [];
let respond;
const context = {document,URLSearchParams,AbortController,Date,FormData:class {constructor(f){this.values=f.values;} *[Symbol.iterator](){yield* Object.entries(this.values);}},history:{replaceState:(_,__,url)=>callbacks.url=url},fetch:(url,opts)=>{calls.push({url,opts});return new Promise(resolve=>{respond=resolve;});},setInterval:(cb,ms)=>{assert.equal(ms,15000);callbacks.tick=cb;},setTimeout:()=>1,clearTimeout:()=>{}};
vm.runInNewContext(fs.readFileSync('assets/js/dashboard-live.js','utf8'),context);
const settle = () => new Promise(resolve=>setImmediate(resolve));
async function run() {
  document.hidden=true; callbacks.tick(); assert.equal(calls.length,0);
  document.hidden=false; callbacks.tick(); callbacks.tick(); assert.equal(calls.length,1);
  respond({status:200,ok:true,json:async()=>({html:'fresh',generated_at:'2026-03-31T12:00:00+08:00'})}); await settle();
  assert.equal(region.innerHTML,'fresh'); assert.match(status.textContent,/Updated/);
  form.values.type='Resolution'; callbacks.submit({preventDefault(){}});
  respond({status:200,ok:true,json:async()=>({html:'filtered',generated_at:'2026-03-31T12:01:00+08:00'})}); await settle();
  assert.match(links[0].href,/type=Resolution/); assert.match(recordLink.href,/type=Resolution/); assert.match(callbacks.url,/type=Resolution/);
  form.values.from='invalid'; callbacks.submit({preventDefault(){}});
  respond({status:422,ok:false,json:async()=>({error:'Choose valid dates.'})}); await settle();
  assert.equal(region.innerHTML,'filtered'); assert.match(status.textContent,/last successful/); assert.doesNotMatch(links[0].href,/invalid/);
  callbacks.tick(); assert.doesNotMatch(calls.at(-1).url,/invalid/);
  respond({status:401,ok:false}); await settle();
  assert.equal(region.innerHTML,''); assert.equal(links[0].href,undefined);
  const count=calls.length; callbacks.tick(); callbacks.refresh(); assert.equal(calls.length,count);
  console.log('PASS: hidden-tab pause, non-overlapping polling, refreshed metrics, applied-filter links, error preservation, expired-session clearing and polling stop.');
}
run().catch(error=>{console.error(error);process.exitCode=1;});
