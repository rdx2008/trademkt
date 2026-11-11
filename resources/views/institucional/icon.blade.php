@php
$paths = [
'industria' => '<path d="M3 21V10l6 3V7l6 3V3h5v18H3Z"/><path d="M7 17h1m4 0h1m4 0h1"/>',
'equipe' => '<circle cx="9" cy="7" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 4a3 3 0 0 1 0 6m3 11v-3a6 6 0 0 0-2-4"/>',
'loja' => '<path d="m3 3 2 14h14l2-10H5m2 14h.01M17 21h.01"/><path d="M9 7v7m6-7v7"/>',
'roteiro' => '<path d="M8 3v4m8-4v4M3 10h18M5 5h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z"/>',
'celular' => '<rect x="6" y="2" width="12" height="20" rx="3"/><path d="M10 5h4m-3 14h2"/>',
'camera' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8" cy="9" r="1"/><path d="m3 17 5-5 4 4 3-3 6 5"/>',
'grafico' => '<path d="M4 21V12h3v9m4 0V7h3v14m4 0V3h3v18"/>',
'pin' => '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
'alvo' => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="4"/><path d="M12 1v5m0 12v5M1 12h5m12 0h5"/>',
'alerta' => '<path d="m12 3 10 18H2L12 3Zm0 6v5m0 3h.01"/>',
'caixa' => '<path d="m12 2 10 5-10 5L2 7l10-5Zm-10 9 10 5 10-5M2 16l10 5 10-5"/>',
'offline' => '<path d="m3 3 18 18M2 8a17 17 0 0 1 3-2m4-2a17 17 0 0 1 13 4M5 12a11 11 0 0 1 4-2m5 0a11 11 0 0 1 5 2m-11 4a6 6 0 0 1 8 0m-4 4h.01"/>',
];
@endphp
<svg class="site-icon" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $paths[$icone ?? 'grafico'] ?? $paths['grafico'] !!}</svg>
