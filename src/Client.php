<?php
declare(strict_types=1);

namespace ApiBndes;

use \RuntimeException;
use \InvalidArgumentException;

/**
 * Cliente para consumir métodos da API de Pagamentos do Cartão BNDES.
 *
 * @package Fmertins\ApiBndes
 */
class Client
{
    // Conforme definições documentação API do BNDES, ver métodos de pagamento e captura do pedido.
    const SITPED_ABERTO = 10;
    const SITPED_AUTORIZADO = 20;
    const SITPED_NAO_AUT = 30;
    const SITPED_CAPTURADO = 40;
    const SITPED_NAO_CAPTURADO = 50;
    
    /**
     * @var int O token de sessão retornado pela API do BNDES ao executar o método "/sessao".
     */
    protected $tokenSessao;
    
    /**
     * @var string CNPJ do fornecedor credenciado no BNDES.
     */
    protected $cnpj;
    
    /**
     * @var string Login do fornecedor credenciado.
     */
    protected $login;
    
    /**
     * @var string Senha do fornecedor credenciado.
     */
    protected $senha;
    
    /**
     * @var string A URL base da API do BNDES.
     */
    protected $url;
    
    /**
     * @var string Path completo nome do arquivo do certificado digital para autenticar na API.
     */
    protected $pathcert;
    
    /**
     * @var string Path completo nome do arquivo da chave privada.
     */
    protected $pathprivkey;
    
    /**
     * @var int Identificador (número) do Pedido no BNDES; retornado pela API BNDES na criação do mesmo.
     */
    protected $numeroPedido;
    
    /**
     * @var array Estrutura conforme JSON de retorno do método "pagamento" da API do BNDES.
     */
    protected $pagamentoRetorno = [];
    
    /**
     * @var array Estrutura conforme JSON de retorno API BNDES.
     */
    protected $capturaRetorno = [];
    
    protected $capturaErroCodigo;
    protected $capturaErroDescr;
    
    /**
     * Construtor, injeta dependências.
     *
     * @param string $cnpj Formato apenas números.
     * @param string $login
     * @param string $senha
     * @param string $url
     * @param string $pathcert
     * @param string $pathprivkey
     * @throws RuntimeException
     */
    public function __construct(
        string $cnpj,
        string $login,
        string $senha,
        string $url,
        string $pathcert,
        string $pathprivkey
    ) {
        $this->cnpj = $cnpj;
        $this->login = $login;
        $this->senha = $senha;
        $this->url = $url;
        $this->pathcert = $pathcert;
        $this->pathprivkey = $pathprivkey;
        
        if (!file_exists($this->pathcert)) {
            throw new RuntimeException("O arquivo-certificado \"{$this->pathcert}\" nao existe");
        }
        
        if (!file_exists($this->pathprivkey)) {
            throw new RuntimeException("O arquivo-privkey \"{$this->pathcert}\" nao existe");
        }
    }
    
    /**
     * @return int|null
     */
    public function getNumeroPedido()
    {
        return $this->numeroPedido;
    }
    
    /**
     * @return array
     */
    public function getPagamentoRetorno(): array
    {
        return $this->pagamentoRetorno;
    }
    
    /**
     * @return array
     */
    public function getCapturaRetorno(): array
    {
        return $this->capturaRetorno;
    }
    
    /**
     * @return string
     */
    public function getCapturaErroCodigo(): string
    {
        return $this->capturaErroCodigo;
    }
    
    /**
     * @return string
     */
    public function getCapturaErroDescr(): string
    {
        return $this->capturaErroDescr;
    }
    
    /**
     * Faz o login na API do BNDES.
     *
     * @throws RuntimeException
     * @throws BndesException
     */
    public function login()
    {
        $ch = $this->newcurl('/v1/sessao', 'POST', false);
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'cnpj' => $this->cnpj,
            'login' => $this->login,
            'senha' => $this->senha
        ]));
        
        $ret = curl_exec($ch);
        
        if ($ret === false) {
            throw new RuntimeException('Requisicao interna curl falhou: ' . curl_error($ch));
        }
        
        $ret = utf8_decode($ret);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($codigoHttp != 201) {
            throw new BndesException(__FUNCTION__ . " - BNDES retornou erro HTTP {$codigoHttp}");
        }
        
        curl_close($ch);
        $token = (int)$ret; // Cast int pois API BNDES retorna um inteiro referente ao token de sessão.
        
        if (!$token || $token <= 0) { // Segurança extra, muito improvável que ocorra...
            throw new BndesException("Token \"{$token}\" retornado pelo BNDES parece invalido");
        }
        
        $this->tokenSessao = $token; // Tudo certo, atribui o token.
    }
    
    /**
     * Realiza logout na API do BNDES.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws BndesException
     */
    public function logout()
    {
        if (!$this->tokenSessao) {
            throw new InvalidArgumentException('Token sessao parece invalido, cancelando...');
        }
        
        $ch = $this->newcurl('/v1/sessao', 'DELETE');
        
        if (curl_exec($ch) === false) {
            throw new RuntimeException('Requisicao interna curl falhou: ' . curl_error($ch));
        }
        
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($codigoHttp != 200) {
            throw new BndesException(__FUNCTION__ . " - BNDES retornou erro HTTP {$codigoHttp}");
        }
        
        curl_close($ch);
    }
    
    /**
     * Realiza simulação de financiamento.
     *
     * @param float $valor
     * @return string JSON conforme retorno API BNDES.
     * @throws BndesException
     */
    public function financiamento(float $valor): string
    {
        $valor = (float)$valor;
        $ch = $this->newcurl("/v1/simulacao/financiamento?valor={$valor}", 'GET');
        $json = curl_exec($ch);
        
        if ($json === false) {
            throw new RuntimeException('Requisicao interna curl falhou: ' . curl_error($ch));
        }
        
        $json = utf8_decode($json);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($codigoHttp != 200) {
            throw new BndesException(__FUNCTION__ . " - BNDES retornou {$this->getErro($json)}");
        }
        
        curl_close($ch);
        return $json;
    }
    
    /**
     * Cria um pedido na API do BNDES.
     *
     * @param string $cnpjComprador Formato apenas números.
     * @param string $binCartao Apenas números, seis primeiros dígitos do Cartão do BNDES.
     * @throws RuntimeException
     * @throws BndesException
     * @return int
     */
    public function criaPedido(string $cnpjComprador, string $binCartao): int
    {
        if (!is_numeric($binCartao)) {
            throw new InvalidArgumentException("O parametro binCartao nao eh numerico!");
        }
        
        $ch = $this->newcurl('/v1/pedido', 'POST');
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'cnpjComprador' => $cnpjComprador,
            'binCartao' => $binCartao
        ]));
        
        $ret = curl_exec($ch);
        
        if ($ret === false) {
            throw new RuntimeException('Requisicao interna curl falhou: ' . curl_error($ch));
        }
        
        $ret = utf8_decode($ret);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($codigoHttp != 201) {
            throw new BndesException(__FUNCTION__ . " - BNDES retornou {$this->getErro($ret)}");
        }
        
        curl_close($ch);
        $this->numeroPedido = (int)$ret; // Atribui o número retornado pela API do BNDES.
        return $this->numeroPedido;
    }
    
    /**
     * Finaliza o Pedido no BNDES.
     *
     * @param array $endereco Dados do endereço do comprador (endereço, número, complemento, etc)
     * @param array[] $itens Itens do Pedido, chaves: produto, precoUnitario, quantidade.
     * @param float $valorPgto
     * @param int $parcelas
     * @return float Valor total do pagamento/transação.
     * @throws InvalidArgumentException
     * @throws BndesException
     */
    public function finalizaPedido(array $endereco, array $itens, float $valorPgto, int $parcelas): float
    {
        if (!$this->numeroPedido) {
            throw new InvalidArgumentException('O numero do pedido esta vazio, impossivel finalizar!');
        }
        
        $ch = $this->newcurl("/v1/pedido/{$this->numeroPedido}", 'PUT');
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'endereco' => $endereco['endereco'],
            'numero' => $endereco['numero'],
            'complemento' => $endereco['complemento'],
            'bairro' => $endereco['bairro'],
            'cep' => $endereco['cep'],
            'municipio' => $endereco['municipio'],
            'uf' => $endereco['uf'],
            'itens' => $itens,
            'valorPagamento' => $valorPgto,
            'parcelas' => $parcelas
        ]));
        
        $ret = curl_exec($ch);
        
        if ($ret === false) {
            throw new RuntimeException('Requisicao interna curl falhou: ' . curl_error($ch));
        }
        
        $ret = utf8_decode($ret);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($codigoHttp != 200) {
            throw new BndesException(__FUNCTION__ . " - BNDES retornou {$this->getErro($ret)}");
        }
        
        curl_close($ch);
        return $valorPgto;
    }
    
    /**
     * Pagamento (pré-autorização) do pedido. Havendo sucesso nesta operação, o limite do cartão é comprometido.
     *
     * @param string $numcart Número do cartão de crédito do comprador, apenas números.
     * @param int $mesval Mês de validade do cartão.
     * @param int $anoval Ano de validade do cartão.
     * @param int $codseg Código de segurança do cartão.
     * @return array As informações de retorno do pagamento.
     * @throws RuntimeException
     * @throws BndesException
     */
    public function pagamento(string $numcart, int $mesval, int $anoval, int $codseg): array
    {
        if (!$this->numeroPedido) {
            throw new InvalidArgumentException('O numero do pedido esta vazio, impossivel realizar pagamento!');
        }
        
        $ch = $this->newcurl("/v1/pedido/{$this->numeroPedido}/pagamento", 'POST');
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'numeroCartao' => $numcart,
            'mesValidade' => $mesval,
            'anoValidade' => $anoval,
            'codigoSeguranca' => (string)$codseg
        ]));
        
        $json = curl_exec($ch);
        
        if ($json === false) {
            throw new RuntimeException('Requisicao interna curl falhou: ' . curl_error($ch));
        }
        
        $json = utf8_decode($json);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($codigoHttp != 200) {
            throw new BndesException(__FUNCTION__ . " - BNDES retornou {$this->getErro($json)}");
        }
        
        curl_close($ch);
        $this->pagamentoRetorno = json_decode(utf8_encode($json));
        return $this->pagamentoRetorno;
    }
    
    /**
     * Confirmação (captura) de pedido.
     *
     * @param int $numeroPedido Número do Pedido no BNDES.
     * @param int $numNotaFiscal
     * @throws RuntimeException
     * @throws BndesException
     * @return array
     */
    public function confirmacaoCaptura(int $numeroPedido, int $numNotaFiscal): array
    {
        $this->numeroPedido = $numeroPedido;
        $ch = $this->newcurl("/v1/pedido/{$this->numeroPedido}/captura", 'POST');
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['notaFiscal' => $numNotaFiscal]));
        $json = curl_exec($ch);
        
        if ($json === false) {
            throw new RuntimeException('Requisicao interna curl falhou: ' . curl_error($ch));
        }
        
        $json = utf8_decode($json);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($codigoHttp != 200) {
            $this->setErroCaptura($json);
            throw new BndesException(__FUNCTION__ . " - BNDES retornou {$this->getErro($json)}");
        }
        
        curl_close($ch);
        $this->capturaRetorno = json_decode(utf8_encode($json));
        return $this->capturaRetorno;
    }
    
    /**
     * Cria o objeto-base do cURL.
     *
     * @param string $metodo
     * @param string $httpVerb GET, PUT, POST, DELETE etc conforme verbos protocolo HTTP.
     * @param bool $cookie true-Com header CTRL do cookie ou false-Sem header cookie CTRL.
     * @return resource
     */
    private function newcurl(string $metodo, string $httpVerb, bool $cookie = true)
    {
        $ch = curl_init($this->url . $metodo);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        //curl_setopt($ch, CURLOPT_STDERR, fopen('curlog.txt', 'a')); Logs do cURL
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $httpVerb);
        curl_setopt($ch, CURLOPT_SSLCERT, $this->pathcert);
        curl_setopt($ch, CURLOPT_SSLKEY, $this->pathprivkey);
        
        $headers = ['Content-Type: application/json'];
        
        if ($cookie) {
            $headers[] = "Cookie: CTRL={$this->tokenSessao}";
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        return $ch;
    }
    
    /**
     * Retorna a primeira mensagem de erro da lista "mensagens" conforme estrutura API do BNDES.
     *
     * @param string $json
     * @return string
     */
    protected function getErro(string $json): string
    {
        $stdobj = json_decode(utf8_encode($json));
        
        if ($stdobj === null) {
            return '(erro desconhecido)';
        }
        
        // IMPORTANTE: utilização "pipe" abaixo no implode do erro código/erro descrição método erro da captura.
        return $stdobj->mensagens[0]->codigo . ' | ' . utf8_decode($stdobj->mensagens[0]->mensagem);
    }
    
    /**
     * Define o erro da captura do cartão.
     *
     * @param string $json
     */
    protected function setErroCaptura(string $json)
    {
        $partes = explode('|', $this->getErro($json));
        $this->capturaErroCodigo = trim($partes[0]);
        $this->capturaErroDescr = trim($partes[1]);
    }
}
