const imageTypes = {png:'image/png',jpg:'image/jpeg',jpeg:'image/jpeg',webp:'image/webp',gif:'image/gif'};

export function documentMime(item) {
  const mime=String(item?.mime || item?.type || '').split(';')[0].trim().toLowerCase();
  if(mime && mime!=='application/octet-stream')return mime;
  const extension=String(item?.name || '').trim().split('.').pop().toLowerCase();
  return imageTypes[extension] || (extension==='pdf'?'application/pdf':mime);
}

export const isDocumentImage=item=>documentMime(item).startsWith('image/');

// Some browsers cannot decode canvas PNGs through createImageBitmap.
// The ordinary image decoder also supports them and is available on older iPads.
export async function decodeDocumentImage(blob,mime) {
  const source=mime && blob.type!==mime?new Blob([blob],{type:mime}):blob;
  if(typeof globalThis.createImageBitmap==='function') {
    try{return await globalThis.createImageBitmap(source);}catch{/* Try the browser image decoder. */}
  }
  const url=URL.createObjectURL(source);
  try{
    return await new Promise((resolve,reject)=>{
      const image=new Image();
      image.onload=()=>resolve(image);
      image.onerror=()=>reject(new Error('Не удалось прочитать изображение. Попробуйте скачать исходный файл.'));
      image.src=url;
    });
  }finally{URL.revokeObjectURL(url);}
}
