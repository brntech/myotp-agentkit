<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>@yield('title', 'Phone verification')</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}" />
  </head>
  <body>
    <main>
      <h1>@yield('heading', 'Phone verification')</h1>
      @yield('content')
    </main>
  </body>
</html>
