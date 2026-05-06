<?php
$files = glob("*.php");
$icon_tags = "\n    <link rel=\"icon\" type=\"image/x-icon\" href=\"icon.ico\">\n    <link rel=\"icon\" type=\"image/png\" href=\"icon.png\">\n</head>";

foreach ($files as $file) {
    if ($file == 'conexao.php' || $file == 'menu.php' || $file == 'logout.php' || $file == 'api_importacao.php') continue;
    
    $content = file_get_contents($file);
    if (strpos($content, '</head>') !== false && strpos($content, 'icon.png') === false) {
        $new_content = str_replace('</head>', $icon_tags, $content);
        file_put_contents($file, $new_content);
        echo "Updated $file\n";
    }
}
?>
