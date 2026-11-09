<?php
// includes/category_list.php
// Reading and showing a product's categories, which queries return as one
// ", "-joined string (see PRODUCT_CATEGORIES_SQL).

function categoryList(?string $joined): array
{
    return ($joined === null || $joined === '') ? [] : explode(', ', $joined);
}

// One category and a "+N" count, so a product with many categories keeps its
// table row to one line. Hovering shows them all.
function renderCategoryList(array $categories): string
{
    if (empty($categories)) return '';

    $all  = htmlspecialchars(implode(', ', $categories));
    $html = '<span class="cat-first">' . htmlspecialchars($categories[0]) . '</span>';
    if (count($categories) > 1) {
        $html .= '<span class="cat-more">+' . (count($categories) - 1) . '</span>';
    }
    return '<span class="cat-list" title="' . $all . '">' . $html . '</span>';
}
