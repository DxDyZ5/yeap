<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo_pagina ?? 'Sistema de Asistencia'; ?></title>
    
    <!-- Fuentes y Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
    
    <style>
        /* CORRECCIÓN DE CARGA: Evita el flash sin formato */
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: #f8fafc;
            opacity: 0; 
            transition: opacity 0.5s ease-in-out;
        }
        body.ready { 
            opacity: 1; 
        }
        
        /* Estilos Globales de Formularios */
        .input-premium { 
            background-color: #ffffff; border: 1px solid #e2e8f0; 
            color: #1e293b !important; font-weight: 700; padding: 0.75rem 1rem;
            border-radius: 0.9rem; width: 100%; font-size: 0.85rem; transition: all 0.2s;
        }
        .input-premium:focus { border-color: #3b82f6; outline: none; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); }
        .label-black { color: #000000 !important; font-weight: 950 !important; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 0.3rem; }
    </style>
</head>
<body class="bg-slate-50">
<?php require 'layout_menu.php'; ?>