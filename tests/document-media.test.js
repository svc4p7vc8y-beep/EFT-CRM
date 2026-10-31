import test from 'node:test';
import assert from 'node:assert/strict';
import {documentMime,isDocumentImage,decodeDocumentImage} from '../src/components/document-media.js';

test('calculator screenshots are recognized with generic or missing MIME metadata',()=>{
  for(const mime of ['',undefined,'application/octet-stream']){
    assert.equal(documentMime({name:'Дом_скриншот.PNG',mime}),'image/png');
    assert.equal(isDocumentImage({name:'Дом_скриншот.PNG',mime}),true);
  }
  assert.equal(documentMime({name:'смета.pdf'}),'application/pdf');
  assert.equal(isDocumentImage({name:'notes.txt',mime:'text/plain'}),false);
  assert.equal(documentMime({type:'IMAGE/PNG; charset=binary'}),'image/png');
});

test('PNG decoder falls back without losing file contents and revokes its temporary URL',async()=>{
  const originalBitmap=globalThis.createImageBitmap,originalImage=globalThis.Image;
  const originalCreate=URL.createObjectURL,originalRevoke=URL.revokeObjectURL;
  const png=new Blob([Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1cAAAAASUVORK5CYII=','base64')],{type:'application/octet-stream'});
  let created,revoked;
  try{
    globalThis.createImageBitmap=async()=>{throw new Error('decoder unsupported');};
    globalThis.Image=class {set src(value){this.width=1;this.height=1;queueMicrotask(()=>this.onload());}};
    URL.createObjectURL=blob=>{created=blob;return 'blob:png';};
    URL.revokeObjectURL=url=>{revoked=url;};
    const image=await decodeDocumentImage(png,'image/png');
    assert.equal(image.width,1);assert.equal(created.type,'image/png');
    assert.deepEqual(await created.arrayBuffer(),await png.arrayBuffer());assert.equal(revoked,'blob:png');
    globalThis.createImageBitmap=undefined;
    assert.equal((await decodeDocumentImage(png,'image/png')).height,1);
    globalThis.Image=class {set src(value){queueMicrotask(()=>this.onerror());}};
    await assert.rejects(decodeDocumentImage(png,'image/png'),/прочитать изображение/);
    assert.equal(revoked,'blob:png');
  }finally{
    globalThis.createImageBitmap=originalBitmap;globalThis.Image=originalImage;
    URL.createObjectURL=originalCreate;URL.revokeObjectURL=originalRevoke;
  }
});
