<!-- ════════════════════════════════════════════
     FOOTER  (includes/footer.php)
════════════════════════════════════════════ -->
<footer class="border-t border-[#D2C8AE] mt-auto" style="background:#261F0E">
    <div class="max-w-5xl mx-auto px-6 h-14 flex items-center justify-between">

        <p class="text-xs" style="color:rgba(210,200,174,0.35)">
            &copy; <?php echo date('Y'); ?> ProVendor &mdash; Academic Prototype
        </p>

        <a href="<?php echo BASE_URL; ?>/pages/about.view.php"
           class="text-xs uppercase tracking-widest transition-opacity"
           style="color:rgba(210,200,174,0.45)"
           onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity=''">
            About Us
        </a>

    </div>
</footer>
