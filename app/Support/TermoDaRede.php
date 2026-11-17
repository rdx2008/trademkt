<?php

namespace App\Support;

use App\Models\Configuracao;

/**
 * Termo de uso compartilhado da rede da plataforma, aceito pelo promotor no cadastro. O texto é
 * padrão e cada agência pode trocá-lo; a versão acompanha o conteúdo (muda o texto, muda a versão).
 * A revisão jurídica do texto é responsabilidade do fornecedor.
 */
class TermoDaRede
{
    public const FINALIDADE = 'rede_compartilhada';

    public const CHAVE = 'termo_rede';

    public static function padrao(): string
    {
        return implode("\n\n", [
            'Ao se cadastrar, você declara que atua como profissional freelancer (autônomo), nos termos da legislação brasileira, e que as informações que está fornecendo são verdadeiras.',
            'Seus dados cadastrais (nome, CPF, contato, cidade, foto) e o registro das suas visitas (fotos com data, hora e localização) ficam na plataforma e são compartilhados com a rede de empresas que ela atende, para identificar quem executa o trabalho e comprovar a presença nos pontos de venda. O CPF é protegido e só a gestão da sua agência o consulta.',
            'Seu nome e sua imagem podem ser usados em materiais de divulgação da plataforma e das empresas atendidas, relacionados ao trabalho executado.',
            'Seu cadastro poderá ser incluído na rede da plataforma, para que você receba oportunidades de trabalho de outras agências. Participar de um trabalho é sempre uma escolha sua.',
            'Você pode pedir informações sobre os seus dados, a correção deles, a exclusão e a revogação deste aceite à sua agência, conforme a Lei Geral de Proteção de Dados (LGPD).',
        ]);
    }

    public static function texto(): string
    {
        $proprio = trim((string) (Configuracao::todas()[self::CHAVE] ?? ''));

        return $proprio !== '' ? $proprio : self::padrao();
    }

    public static function versao(?string $texto = null): string
    {
        return substr(sha1($texto ?? self::texto()), 0, 12);
    }
}
