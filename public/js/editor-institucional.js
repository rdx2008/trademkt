const editor = document.getElementById('editor-site');
if(editor){
 let dirty=false; const state=document.getElementById('editor-state');
 const mark=()=>{dirty=true;state.textContent='Há alterações não salvas. Salve antes de abrir a prévia.';};
 editor.addEventListener('input',mark);editor.addEventListener('change',mark);
 editor.addEventListener('click',event=>{
  const remove=event.target.closest('[data-remove]');if(remove){remove.closest('[data-item]').remove();mark();}
  const add=event.target.closest('[data-add]');if(!add)return;
  const key=add.dataset.add;const list=editor.querySelector('[data-items="'+key+'"]');
  if(list.querySelectorAll('[data-item]').length>=12){alert('Máximo de 12 itens por seção.');return;}
  const index=Number(list.dataset.next);list.dataset.next=String(index+1);
  const template=document.getElementById('template-'+key);
  list.insertAdjacentHTML('beforeend',template.innerHTML.replaceAll('__INDEX__',String(index)));
  list.lastElementChild.querySelector('input').focus();mark();
 });
 editor.addEventListener('invalid',event=>{let detail=event.target.closest('details');while(detail){detail.open=true;detail=detail.parentElement.closest('details');}},true);
 editor.addEventListener('submit',()=>{dirty=false;});
 window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
}
