<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('queue:prune-failed --hours=168')->daily();

// Visitas previstas dos contratos (semana atual e seguinte). Diário para pegar contratos novos.
Schedule::command('roteiros:gerar')->dailyAt('04:30')->withoutOverlapping();
