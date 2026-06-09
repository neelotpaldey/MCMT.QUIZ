<?php // developed by @neelotpal.dey ?>
<style>
.pw-field-wrap{position:relative}
.pw-field-wrap>input,.pw-field-wrap>.form-control{padding-right:2.75rem!important}
.pw-toggle-btn{
  position:absolute;right:10px;bottom:12px;
  background:transparent;border:none;cursor:pointer;padding:4px 6px;
  font-size:1rem;line-height:1;opacity:.55;transition:opacity .15s;z-index:2
}
.pw-field-wrap .iw .pw-toggle-btn{bottom:14px}
.pw-toggle-btn:hover{opacity:1}
</style>
<script>
(function(){
  function isSecretInput(el){
    return el.type==='password'||el.classList.contains('secret-field')
      ||/api[_-]?key/i.test(el.name||'')||/Key$/i.test(el.id||'');
  }
  function init(){
    document.querySelectorAll('input').forEach(function(input){
      if(input.dataset.pwToggle||input.type==='hidden'||!isSecretInput(input))return;
      input.dataset.pwToggle='1';
      if(input.classList.contains('secret-field')&&input.type==='text'){
        input.type='password';
      }
      var parent=input.parentElement;
      if(!parent.classList.contains('pw-field-wrap')){
        parent.classList.add('pw-field-wrap');
      }
      var btn=document.createElement('button');
      btn.type='button';
      btn.className='pw-toggle-btn';
      btn.setAttribute('aria-label','Show');
      btn.textContent='👁';
      btn.addEventListener('click',function(){
        var masked=input.type==='password';
        input.type=masked?'text':'password';
        btn.textContent=masked?'🙈':'👁';
        btn.setAttribute('aria-label',masked?'Hide':'Show');
      });
      parent.appendChild(btn);
    });
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);
  else init();
})();
</script>
