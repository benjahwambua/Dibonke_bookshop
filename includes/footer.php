<?php
declare(strict_types=1);
?>
</div>
<footer class="app-footer">
    <div class="footer-brand">
        <span class="footer-mark"><i class="fas fa-book-open"></i></span>
        <span><strong>Dibonke Bookshop</strong><small>Books, stationery &amp; general retail</small></span>
    </div>
    <div class="footer-meta"><span>&copy; <?= date('Y') ?> Dibonke Bookshop. All rights reserved.</span><span class="footer-separator">•</span><span>Retail Management System</span><span class="footer-separator">•</span><span>Developed by <a class="footer-credit" href="https://www.flexiscripthub.africa" target="_blank" rel="noopener noreferrer">FlexiScriptHub</a></span><span class="footer-separator">•</span><span>Support: <a href="tel:0705259931">0705259931</a></span></div>
</footer>
<style>
.app-footer{margin-left:var(--app-sidebar-width,264px);display:flex;align-items:center;justify-content:space-between;gap:18px;padding:16px 30px;background:#fff;border-top:1px solid var(--app-border,#e5eaf0);color:#8491a0;font-size:11px}
.footer-brand{display:flex;align-items:center;gap:10px}.footer-mark{width:30px;height:30px;display:grid;place-items:center;border-radius:9px;background:#edf2f7;color:#17324d}
.footer-brand strong{display:block;color:#34465a;font-size:11px}.footer-brand small{display:block;margin-top:2px;color:#8a97a5;font-size:10px}
.footer-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.footer-meta a{color:#34465a;text-decoration:none;font-weight:700}.footer-meta a:hover{text-decoration:underline}.footer-credit{color:#34465a}.footer-separator{color:#d6a84f}
@media(max-width:768px){.app-footer{margin-left:0;padding:15px 18px;flex-direction:column;align-items:flex-start;gap:10px}.footer-meta{gap:7px}}
</style>
<script>
(function(){
    const sidebar=document.getElementById('appSidebar');
    const toggle=document.getElementById('sidebarToggle');
    const backdrop=document.getElementById('sidebarBackdrop');
    if(!sidebar||!toggle||!backdrop)return;
    const icon=toggle.querySelector('i');
    const isMobile=()=>window.innerWidth<=768;
    const closeMobile=()=>{sidebar.classList.remove('is-open');backdrop.classList.remove('is-visible');toggle.setAttribute('aria-expanded','false');toggle.setAttribute('aria-label','Open navigation');if(icon)icon.className='fas fa-bars';};
    const openMobile=()=>{sidebar.classList.add('is-open');backdrop.classList.add('is-visible');toggle.setAttribute('aria-expanded','true');toggle.setAttribute('aria-label','Close navigation');if(icon)icon.className='fas fa-times';};
    const applyDesktopState=()=>{const collapsed=localStorage.getItem('dibonkeSidebarCollapsed')==='1';document.documentElement.classList.toggle('sidebar-collapsed',collapsed);toggle.setAttribute('aria-expanded',String(!collapsed));toggle.setAttribute('aria-label',collapsed?'Expand navigation':'Collapse navigation');if(icon)icon.className=collapsed?'fas fa-angles-right':'fas fa-angles-left';};
    if(!isMobile())applyDesktopState();
    toggle.addEventListener('click',()=>{
        if(isMobile()){sidebar.classList.contains('is-open')?closeMobile():openMobile();return;}
        const collapsed=!document.documentElement.classList.contains('sidebar-collapsed');
        document.documentElement.classList.toggle('sidebar-collapsed',collapsed);
        localStorage.setItem('dibonkeSidebarCollapsed',collapsed?'1':'0');
        toggle.setAttribute('aria-expanded',String(!collapsed));
        toggle.setAttribute('aria-label',collapsed?'Expand navigation':'Collapse navigation');
        if(icon)icon.className=collapsed?'fas fa-angles-right':'fas fa-angles-left';
    });
    backdrop.addEventListener('click',closeMobile);
    document.addEventListener('keydown',event=>{if(event.key==='Escape')closeMobile();});
    sidebar.querySelectorAll('a').forEach(link=>link.addEventListener('click',()=>{if(isMobile())closeMobile();}));
    window.addEventListener('resize',()=>{if(isMobile()){closeMobile();document.documentElement.classList.remove('sidebar-collapsed');}else{closeMobile();applyDesktopState();}});
})();
</script>
</body>
</html>
