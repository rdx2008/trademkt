@if (session('redistribuir'))
    <div class="cartao" role="alert">
        <h2>Visitas já atribuídas</h2>
        <p>{{ session('redistribuir')['n'] }} visita(s) já atribuída(s) a este promotor no período. Passe para colegas habilitados e livres, da mesma cidade quando houver.</p>
        <a class="botao" href="{{ session('redistribuir')['url'] }}">Ver e redistribuir</a>
    </div>
@endif
