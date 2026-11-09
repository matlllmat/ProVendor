<?php
// includes/toast.php
// Reusable toast notifications. Include once before </body> on any page that
// wants to call showToast() (from assets/global_js/toast.js).
//
// Trigger from JS:
//   showToast('Saved', 'success');
//   showToast('Network error', 'error');
//   showToast('Heads up...');                     // defaults to 'info'
//   showToast('Reminder', 'warning', { bottom: true, ms: 10000 });  // lower left, custom time
?>

<!-- ════════════════════════════════════════════
     TOAST CONTAINER
════════════════════════════════════════════ -->
<div id="toast-container" class="toast-container" aria-live="polite" aria-atomic="false"></div>
<div id="toast-container-bottom" class="toast-container toast-container-bottom" aria-live="polite" aria-atomic="false"></div>

<script src="<?php echo BASE_URL; ?>/assets/global_js/toast.js?v=<?php echo filemtime(__DIR__ . '/../assets/global_js/toast.js'); ?>"></script>
