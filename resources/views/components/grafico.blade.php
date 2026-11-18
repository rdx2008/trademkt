@props(['config'])
@once
    @push('scripts')
        <script src="{{ asset('tema/vendor/apexchart/apexchart.js') }}"></script>
        <script src="{{ asset('js/graficos.js') }}"></script>
    @endpush
@endonce
<div {{ $attributes->merge(['class' => 'grafico']) }} data-grafico="{{ json_encode($config, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) }}"></div>
