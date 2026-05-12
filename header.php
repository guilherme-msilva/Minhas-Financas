<?php
// Determina se a classe dark será aplicada no html
$tema_class = (isset($_SESSION['tema']) && $_SESSION['tema'] === 'CLARO') ? '' : 'dark';
?>
<!DOCTYPE html>
<html lang="pt-br" class="<?php echo $tema_class; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) : 'Minhas Finanças'; ?></title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="shortcut icon" href="favicon.ico">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {}
            }
        }
    </script>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Global CSS -->
    <style>
        body {
            font-family: 'Outfit', sans-serif;
            overflow-x: hidden;
        }
        
        /* Utilitário global para esconder a barra de rolagem onde necessário */
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        /* Animações e Bolhas de Fundo */
        .blob {
            position: fixed;
            filter: blur(80px);
            z-index: -1;
            animation: move 10s infinite alternate ease-in-out;
            transition: opacity 0.5s ease;
        }
        .blob-1 { top: -10%; left: -10%; width: 500px; height: 500px; background: #3b82f6; border-radius: 50%; }
        .blob-2 { bottom: -10%; right: -10%; width: 600px; height: 600px; background: #8b5cf6; border-radius: 50%; animation-delay: 2s; }
        .blob-3 { top: 40%; left: 40%; width: 400px; height: 400px; background: #06b6d4; border-radius: 50%; animation-delay: 4s; }
        
        /* Controle de opacidade pelo tema */
        html.dark .blob { opacity: 0.5; }
        html:not(.dark) .blob { opacity: 0.15; }
        
        @keyframes move {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(50px, -50px) scale(1.1); }
        }
    </style>
    
    <?php if (isset($extra_head)) echo $extra_head; ?>
</head>
<body <?php echo isset($body_id) ? 'id="' . htmlspecialchars($body_id) . '"' : ''; ?> class="<?php echo isset($body_class) ? htmlspecialchars($body_class) : 'min-h-screen relative pb-20 bg-slate-50 text-slate-800 dark:bg-[#0f172a] dark:text-[#f8fafc] transition-colors duration-300'; ?>">
    <!-- Fundo Animado -->
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>
    <div class="blob blob-3"></div>
