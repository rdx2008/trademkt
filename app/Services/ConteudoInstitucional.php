<?php

namespace App\Services;

use App\Models\SiteInstitucional;

class ConteudoInstitucional
{
    public static function padrao(): array
    {
        return json_decode(file_get_contents(resource_path('site-institucional.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function registro(): SiteInstitucional
    {
        return SiteInstitucional::firstOrCreate(['id' => 1], ['rascunho' => self::padrao()]);
    }

    public function publico(): array
    {
        return SiteInstitucional::find(1)?->publicado ?? self::padrao();
    }
}
