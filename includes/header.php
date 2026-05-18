<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token"
          content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES); ?>"
          data-base="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES); ?>">
    <title><?php echo htmlspecialchars($pageTitle ?? 'ProVendor'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/global_css/app.css">
    <?php if (!empty($pageCss)): ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/pages/css/<?php echo htmlspecialchars($pageCss); ?>">
    <?php endif; ?>
    <?php if (!empty($extraCss)): ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/pages/css/<?php echo htmlspecialchars($extraCss); ?>">
    <?php endif; ?>
    <script src="<?php echo BASE_URL; ?>/assets/global_js/csrf.js"></script>
</head>
<body class="<?php echo htmlspecialchars($bodyClass ?? 'bg-[#F0E8D0] min-h-screen dot-pattern-light'); ?>">
