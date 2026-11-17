<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>SMARTICCT</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @livewireStyles
    @fluxAppearance
</head>
<body class="min-h-screen flex flex-col bg-light-primary dark:bg-dark-primary antialiased">
    
    <flux:main class="p-0!">

      {{ $slot }}

    </flux:main>

    @livewireScripts
    @fluxScripts

</body>
</html>