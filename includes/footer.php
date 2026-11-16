<?php
declare(strict_types=1);
?>
</div>
<footer class="app-footer">
    <div class="footer-brand">
        <span class="footer-mark"><i class="fas fa-book-open"></i></span>
        <span><strong>Dibonke Bookshop</strong><small>Books, stationery &amp; general retail</small></span>
    </div>
    <div class="footer-meta"><span>&copy; <?= date('Y') ?> Dibonke Bookshop. All rights reserved.</span><span class="footer-separator">•</span><span>Retail Management System</span></div>
</footer>
<style>
.app-footer{margin-left:var(--app-sidebar-width,264px);display:flex;align-items:center;justify-content:space-between;gap:18px;padding:16px 30px;background:#fff;border-top:1px solid var(--app-border,#e5eaf0);color:#8491a0;font-size:11px}
.footer-brand{display:flex;align-items:center;gap:10px}.footer-mark{width:30px;height:30px;display:grid;place-items:center;border-radius:9px;background:#edf2f7;color:#17324d}
.footer-brand strong{display:block;color:#34465a;font-size:11px}.footer-brand small{display:block;margin-top:2px;color:#8a97a5;font-size:10px}
.footer-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.footer-separator{color:#d6a84f}
@media(max-width:700px){.app-footer{margin-left:0;padding:15px 18px;flex-direction:column;align-items:flex-start;gap:10px}.footer-meta{gap:7px}}
</style>
<script>
(function(){
    const sidebar=document.getElementById('appSidebar');
    const toggle=document.getElementById('sidebarToggle');
    const backdrop=document.getElementById('sidebarBackdrop');
    if(!sidebar||!toggle||!backdrop)return;
    const close=()=>{sidebar.classList.remove('is-open');backdrop.classList.remove('is-visible');toggle.setAttribute('aria-expanded','false');toggle.setAttribute('aria-label','Open navigation');};
    const open=()=>{sidebar.classList.add('is-open');backdrop.classList.add('is-visible');toggle.setAttribute('aria-expanded','true');toggle.setAttribute('aria-label','Close navigation');};
    toggle.addEventListener('click',()=>sidebar.classList.contains('is-open')?close():open());
    backdrop.addEventListener('click',close);
    document.addEventListener('keydown',event=>{if(event.key==='Escape')close();});
    sidebar.querySelectorAll('a').forEach(link=>link.addEventListener('click',()=>{if(window.innerWidth<=700)close();}));
    window.addEventListener('resize',()=>{if(window.innerWidth>700)close();});
})();
</script>
</body>
</html>
