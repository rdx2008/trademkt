@props(['rotulo', 'valor', 'icone' => 'flaticon-381-controls-3', 'cor' => 'primary', 'ajuda' => null, 'selo' => null, 'seloCor' => null, 'url' => null])
{{-- Cartão de indicador no padrão "widget-stat" do tema. --}}
<div class="widget-stat card">
    <div class="card-body p-4">
        <div class="media ai-icon">
            <span class="me-3 bgl-{{ $cor }} text-{{ $cor }}">
                <i class="{{ $icone }} fs-26"></i>
            </span>
            <div class="media-body">
                <p class="mb-1">@if ($url)<a href="{{ $url }}" class="text-reset">{{ $rotulo }}</a>@else{{ $rotulo }}@endif</p>
                <h4 class="mb-0">{{ $valor }}</h4>
                @if ($selo)<span class="badge badge-{{ $seloCor ?? $cor }}">{{ $selo }}</span>@endif
                @if ($ajuda)<small class="d-block text-muted mt-1">{{ $ajuda }}</small>@endif
            </div>
        </div>
    </div>
</div>
