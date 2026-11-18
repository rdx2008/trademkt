<?php

namespace App\Services\Marca;

use App\Models\Configuracao;
use App\Services\Operacao\Equipe;
use App\Support\Contexto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Marca própria (white label) de uma instalação: nome exibido, cor principal e logos.
 *
 * Os valores ficam em `configuracoes`; os arquivos, na pasta privada do cliente e servidos por
 * rotas públicas versionadas (o login precisa da marca antes de qualquer autenticação).
 * Sem nada configurado, vale o visual padrão do tema.
 */
class Aparencia
{
    public const COR_PADRAO = '#222fb9';

    /** Contraste mínimo da cor com o branco, para o texto dos botões continuar legível. */
    public const CONTRASTE_MINIMO = 3.0;

    /** Tipos de arquivo: chave da configuração, lado máximo em pixels. */
    public const ARQUIVOS = [
        'claro' => ['chave' => 'marca.logo_claro', 'largura' => 480, 'altura' => 160],
        'escuro' => ['chave' => 'marca.logo_escuro', 'largura' => 480, 'altura' => 160],
        'icone' => ['chave' => 'marca.icone', 'largura' => 256, 'altura' => 256],
    ];

    public const PASTA = 'marca';

    /** @var array<string, self> leitura em memória por cliente (uma requisição) */
    private static array $atual = [];

    /**
     * @param  array<string, string>  $valores
     * @param  ?string  $raiz  pasta dos arquivos quando não é a do disco em uso (operação aberta pela agência)
     */
    private function __construct(private array $valores, private ?string $raiz = null) {}

    /**
     * Aparência da instalação em que o usuário entrou (lida uma vez por requisição). Quando a
     * agência está dentro da operação de uma indústria, a marca continua sendo a da agência.
     */
    public static function atual(): self
    {
        $instalacao = Contexto::instalacao();
        $cliente = (string) $instalacao?->id;

        return self::$atual[$cliente] ??= new self(
            $instalacao?->whiteLabelAtivo() === false ? [] : (Contexto::viaAgencia()
                ? Configuracao::on(Equipe::conexao($instalacao))->pluck('valor', 'chave')->all()
                : Configuracao::todas()),
            Contexto::viaAgencia() ? storage_path('clientes/'.$instalacao->id.'/app') : null,
        );
    }

    /** Descarta a leitura em memória (depois de salvar e nos testes). */
    public static function esquecer(): void
    {
        self::$atual = [];
    }

    public function nome(): string
    {
        $nome = trim((string) ($this->valores['marca.nome'] ?? ''));

        return $nome !== '' ? $nome : (string) Contexto::instalacao()?->nome;
    }

    public function nomePersonalizado(): ?string
    {
        $nome = trim((string) ($this->valores['marca.nome'] ?? ''));

        return $nome !== '' ? $nome : null;
    }

    public function cor(): string
    {
        return self::normalizarCor($this->valores['marca.cor'] ?? null) ?? self::COR_PADRAO;
    }

    public function temCorPropria(): bool
    {
        $cor = self::normalizarCor($this->valores['marca.cor'] ?? null);

        return $cor !== null && $cor !== self::COR_PADRAO;
    }

    public function temArquivo(string $tipo): bool
    {
        $caminho = $this->caminho($tipo);

        return $caminho !== null && ($this->raiz !== null ? file_exists($this->raiz.'/'.$caminho) : Storage::disk('local')->exists($caminho));
    }

    public function caminho(string $tipo): ?string
    {
        $chave = self::ARQUIVOS[$tipo]['chave'] ?? null;

        return $chave ? ($this->valores[$chave] ?? null) : null;
    }

    /** Versão para o parâmetro `?v=` dos arquivos: muda quando qualquer configuração muda. */
    public function versao(): string
    {
        return substr(md5(json_encode($this->valores)), 0, 10);
    }

    /** URL pública do logo/ícone, ou null se não houver. */
    public function url(string $tipo): ?string
    {
        return $this->temArquivo($tipo) ? route('marca.arquivo', ['tipo' => $tipo, 'v' => $this->versao()]) : null;
    }

    /** Logo para fundo claro com sobra para o escuro e vice-versa. */
    public function urlLogoParaClaro(): ?string
    {
        return $this->url('claro') ?? $this->url('escuro');
    }

    public function urlLogoParaEscuro(): ?string
    {
        return $this->url('escuro') ?? $this->url('claro');
    }

    public function urlFavicon(): string
    {
        return $this->url('icone') ?? asset('tema/images/favicon.png');
    }

    // ------------------------------------------------------------------ gravação

    /**
     * Salva nome, cor e arquivos. Arquivos novos são recodificados em PNG (sem metadados);
     * `remover` lista os tipos a apagar.
     *
     * @param  array{nome?: ?string, cor?: ?string}  $dados
     * @param  array<string, UploadedFile>  $arquivos
     * @param  list<string>  $remover
     *
     * @throws ValidationException
     */
    public function salvar(array $dados, array $arquivos = [], array $remover = []): void
    {
        $cor = isset($dados['cor']) && $dados['cor'] !== '' ? self::normalizarCor($dados['cor']) : null;
        if (isset($dados['cor']) && $dados['cor'] !== '') {
            if ($cor === null) {
                throw ValidationException::withMessages(['cor' => 'Informe a cor no formato #RRGGBB.']);
            }
            if (self::contraste($cor, '#ffffff') < self::CONTRASTE_MINIMO) {
                throw ValidationException::withMessages(['cor' => 'Essa cor é clara demais: o texto branco dos botões ficaria ilegível. Escolha um tom mais escuro.']);
            }
        }

        $novos = [];
        foreach ($arquivos as $tipo => $arquivo) {
            $novos[$tipo] = $this->guardar($tipo, $arquivo);
        }

        Configuracao::gravar('marca.nome', isset($dados['nome']) ? trim((string) $dados['nome']) : null);
        Configuracao::gravar('marca.cor', $cor === self::COR_PADRAO ? null : $cor);

        foreach (self::ARQUIVOS as $tipo => $definicao) {
            $antigo = $this->caminho($tipo);
            if (isset($novos[$tipo])) {
                Configuracao::gravar($definicao['chave'], $novos[$tipo]);
            } elseif (in_array($tipo, $remover, true)) {
                Configuracao::gravar($definicao['chave'], null);
            } else {
                continue;
            }
            if ($antigo && $antigo !== ($novos[$tipo] ?? null)) {
                Storage::disk('local')->delete($antigo);
            }
        }

        self::esquecer();
    }

    /** Volta ao visual padrão e apaga os arquivos. */
    public function restaurar(): void
    {
        foreach (array_keys(self::ARQUIVOS) as $tipo) {
            if ($caminho = $this->caminho($tipo)) {
                Storage::disk('local')->delete($caminho);
            }
        }
        Configuracao::whereIn('chave', ['marca.nome', 'marca.cor', ...array_column(self::ARQUIVOS, 'chave')])->delete();

        self::esquecer();
    }

    /**
     * Recodifica a imagem em PNG (limita o tamanho, mantém a transparência, descarta metadados).
     * Retorna o caminho no disco privado do cliente.
     */
    private function guardar(string $tipo, UploadedFile $arquivo): string
    {
        $definicao = self::ARQUIVOS[$tipo];
        $campo = $tipo === 'icone' ? 'icone' : 'logo_'.$tipo;

        $bytes = (string) file_get_contents($arquivo->getRealPath());
        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            throw ValidationException::withMessages([$campo => 'Envie uma imagem PNG, JPG ou WebP.']);
        }
        [$largura, $altura] = $info;
        if ($largura < 16 || $altura < 16 || $largura * $altura > 25_000_000) {
            throw ValidationException::withMessages([$campo => 'Dimensões da imagem fora do aceitável (mínimo 16 px).']);
        }

        $origem = @imagecreatefromstring($bytes);
        if (! $origem) {
            throw ValidationException::withMessages([$campo => 'Não foi possível ler a imagem.']);
        }

        $escala = min(1, $definicao['largura'] / $largura, $definicao['altura'] / $altura);
        $novaLargura = max(1, (int) round($largura * $escala));
        $novaAltura = max(1, (int) round($altura * $escala));

        $destino = imagecreatetruecolor($novaLargura, $novaAltura);
        imagealphablending($destino, false);
        imagesavealpha($destino, true);
        imagefill($destino, 0, 0, imagecolorallocatealpha($destino, 0, 0, 0, 127));
        imagecopyresampled($destino, $origem, 0, 0, 0, 0, $novaLargura, $novaAltura, $largura, $altura);

        ob_start();
        imagepng($destino, null, 9);
        $png = (string) ob_get_clean();

        $caminho = self::PASTA.'/'.$tipo.'-'.Str::random(12).'.png';
        Storage::disk('local')->put($caminho, $png);

        return $caminho;
    }

    // ----------------------------------------------------------------------- cores

    /** "#abc", "ABCDEF" ou "#AbCdEf" → "#aabbcc"/"#abcdef"; null se inválida. */
    public static function normalizarCor(?string $cor): ?string
    {
        $cor = strtolower(trim((string) $cor));
        if ($cor === '') {
            return null;
        }
        $cor = ltrim($cor, '#');
        if (preg_match('/^[0-9a-f]{3}$/', $cor)) {
            $cor = $cor[0].$cor[0].$cor[1].$cor[1].$cor[2].$cor[2];
        }

        return preg_match('/^[0-9a-f]{6}$/', $cor) ? '#'.$cor : null;
    }

    /** @return array{int, int, int} */
    public static function rgb(string $cor): array
    {
        $cor = ltrim($cor, '#');

        return [hexdec(substr($cor, 0, 2)), hexdec(substr($cor, 2, 2)), hexdec(substr($cor, 4, 2))];
    }

    public static function hex(int $r, int $g, int $b): string
    {
        return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
    }

    /** Mistura a cor com outra (fator 0 = a própria cor, 1 = a outra). */
    public static function misturar(string $cor, string $outra, float $fator): string
    {
        [$r1, $g1, $b1] = self::rgb($cor);
        [$r2, $g2, $b2] = self::rgb($outra);

        return self::hex((int) round($r1 + ($r2 - $r1) * $fator), (int) round($g1 + ($g2 - $g1) * $fator), (int) round($b1 + ($b2 - $b1) * $fator));
    }

    /** Luminância relativa (WCAG 2). */
    public static function luminancia(string $cor): float
    {
        $canais = array_map(function (int $c) {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($cor));

        return 0.2126 * $canais[0] + 0.7152 * $canais[1] + 0.0722 * $canais[2];
    }

    /** Razão de contraste entre duas cores (de 1 a 21). */
    public static function contraste(string $a, string $b): float
    {
        $la = self::luminancia($a);
        $lb = self::luminancia($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    // ------------------------------------------------------------------------- CSS

    /**
     * Folha de estilo que redefine a cor principal do tema. O tema usa variáveis
     * (`--primary`, `--rgba-primary-N`) e também a cor escrita direto em vários componentes do
     * Bootstrap e no menu; aqui entram os dois jeitos.
     */
    public function css(): string
    {
        $c = $this->cor();
        $hover = self::misturar($c, '#000000', 0.18);
        $escura = self::misturar($c, '#000000', 0.62);
        [$r, $g, $b] = self::rgb($c);
        $rgba = fn (float $a) => "rgba({$r}, {$g}, {$b}, {$a})";

        $variaveis = "  --primary: {$c};\n  --primary-hover: {$hover};\n  --primary-dark: {$escura};\n  --bs-primary: {$c};\n  --bs-primary-rgb: {$r}, {$g}, {$b};\n";
        for ($i = 1; $i <= 9; $i++) {
            $variaveis .= "  --rgba-primary-{$i}: ".$rgba($i / 10).";\n";
        }

        return <<<CSS
/* Marca própria da instalação: gerado por App\\Services\\Marca\\Aparencia */
:root, [data-primary=color_1], [data-primary=color_3] {
{$variaveis}}
.btn-primary { --bs-btn-bg: {$c}; --bs-btn-border-color: {$c}; --bs-btn-hover-bg: {$hover}; --bs-btn-hover-border-color: {$hover}; --bs-btn-active-bg: {$hover}; --bs-btn-active-border-color: {$hover}; --bs-btn-disabled-bg: {$c}; --bs-btn-disabled-border-color: {$c}; --bs-btn-focus-shadow-rgb: {$r}, {$g}, {$b}; }
.btn-outline-primary { --bs-btn-color: {$c}; --bs-btn-border-color: {$c}; --bs-btn-hover-bg: {$c}; --bs-btn-hover-border-color: {$c}; --bs-btn-active-bg: {$c}; --bs-btn-active-border-color: {$c}; --bs-btn-disabled-color: {$c}; --bs-btn-disabled-border-color: {$c}; }
.shadow.btn-primary { box-shadow: 0 0.313rem 0.938rem 0 {$rgba(0.2)}; }
.form-check-input:checked, .form-check-input[type=checkbox]:indeterminate { background-color: {$c}; border-color: {$c}; }
.form-control:focus, .form-select:focus, .form-check-input:focus { border-color: {$c}; box-shadow: 0 0 0 0.25rem {$rgba(0.25)}; }
.progress, .progress-stacked { --bs-progress-bar-bg: {$c}; }
.nav-pills { --bs-nav-pills-link-active-bg: {$c}; }
.dropdown-menu { --bs-dropdown-link-active-bg: {$c}; }
.list-group { --bs-list-group-active-bg: {$c}; }
.dropdown-menu .dropdown-item:hover, .dropdown-menu .dropdown-item:focus, .card-link:hover, .icon-primary i, .default-select.style-1 .btn, .default-select.style-2 .btn { color: {$c} !important; }
.default-select.style-2 .btn.active, .default-select.style-2 .btn:focus { background-color: {$c}; color: #fff !important; }
.badge-primary { background-color: {$c}; }
.badge-primary.light { background-color: {$rgba(0.12)}; color: {$c}; }
.bgl-primary { background-color: {$rgba(0.1)}; }
[data-theme-version=dark] .bgl-primary { background-color: {$rgba(0.2)}; }
.text-primary { color: {$c} !important; }
.bg-primary { background-color: {$c} !important; }
.border-primary { border-color: {$c} !important; }
a { --bs-link-color-rgb: {$r}, {$g}, {$b}; }
[data-nav-headerbg=color_3] .nav-header, [data-nav-headerbg=color_3][data-theme-version=dark] .nav-header,
[data-sidebarbg=color_3] .deznav, [data-sidebarbg=color_3][data-theme-version=dark] .deznav,
[data-sidebarbg=color_3][data-theme-version=dark] .menu-toggle .deznav .metismenu li > ul { background-color: {$c} !important; }
[data-sidebarbg=color_3] .deznav .metismenu > li:hover > a i, [data-sidebarbg=color_3] .deznav .metismenu > li.mm-active > a i,
[data-sidebarbg=color_3] .deznav .metismenu > li:focus > a i, [data-sidebarbg=color_3] .deznav .menu-tabs li a.active { color: {$c} !important; }
[data-sidebarbg=color_3] .deznav .metismenu > li:hover > a, [data-sidebarbg=color_3] .deznav .metismenu > li.mm-active > a, [data-sidebarbg=color_3] .deznav .metismenu > li:focus > a { box-shadow: 0 10px 20px -10px {$hover}; }
.header-right .avatar-iniciais, .pino-numero { background: {$c}; }
.botao { background: {$c}; border-color: {$c}; }
.botao:hover { background: {$hover}; border-color: {$hover}; }
.botao.secundario { background: transparent; color: {$c}; }
.botao.secundario:hover { background: {$c}; color: #fff; }
.link-botao, .marca-entrada, .inicial-marca { color: {$c}; }
.rank-lista .barra > span { background: {$c}; }
#conteudo input:focus, #conteudo select:focus, #conteudo textarea:focus { border-color: {$c}; box-shadow: 0 0 0 .15rem {$rgba(0.12)}; }
.bg-soft-primary { background: {$rgba(0.1)}; color: {$c}; }

CSS;
    }
}
