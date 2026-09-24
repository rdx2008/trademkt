<?php

use Illuminate\Support\Facades\Schedule;

// Tarefas agendadas entram nas próximas fases (ex.: alerta de visitas não executadas).
Schedule::command('queue:prune-failed --hours=168')->daily();
