<?php

namespace App\Services\Financeiro\Nfse;

use DOMDocument;
use DOMElement;

/**
 * Assinatura XMLDSig ENVELOPADA (padrão dos DF-e nacionais): Reference URI="#<Id>" no elemento
 * assinado, transforms enveloped-signature + C14N 1.0, <Signature> como irmão logo após o elemento.
 * Algoritmo configurável (financeiro.nfse_assinatura: sha1 | sha256).
 */
class XmlSigner
{
    private const DS = 'http://www.w3.org/2000/09/xmldsig#';
    private const C14N = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';

    /** @param string $certPem certificado X.509 (PEM)  @param string $keyPem chave privada (PEM) */
    public function assinar(DOMDocument $doc, string $tagAssinada, string $certPem, string $keyPem, ?string $algoritmo = null): DOMDocument
    {
        $alg = $algoritmo ?? config('financeiro.nfse_assinatura', 'sha1');
        [$digestUri, $sigUri, $hashAlg, $sslAlg] = $alg === 'sha256'
            ? ['http://www.w3.org/2001/04/xmlenc#sha256', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256', 'sha256', OPENSSL_ALGO_SHA256]
            : ['http://www.w3.org/2000/09/xmldsig#sha1', 'http://www.w3.org/2000/09/xmldsig#rsa-sha1', 'sha1', OPENSSL_ALGO_SHA1];

        /** @var DOMElement|null $alvo */
        $alvo = $doc->getElementsByTagName($tagAssinada)->item(0);
        if (! $alvo || ! $alvo->hasAttribute('Id')) {
            throw new \RuntimeException("Elemento {$tagAssinada} com atributo Id não encontrado.");
        }
        $alvo->setIdAttribute('Id', true);
        $id = $alvo->getAttribute('Id');

        $digest = base64_encode(hash($hashAlg, $alvo->C14N(false, false), true));

        $sig = $doc->createElementNS(self::DS, 'Signature');
        $alvo->parentNode->insertBefore($sig, $alvo->nextSibling);
        $si = $this->ds($doc, $sig, 'SignedInfo');
        $this->ds($doc, $si, 'CanonicalizationMethod')->setAttribute('Algorithm', self::C14N);
        $this->ds($doc, $si, 'SignatureMethod')->setAttribute('Algorithm', $sigUri);
        $ref = $this->ds($doc, $si, 'Reference');
        $ref->setAttribute('URI', '#' . $id);
        $tr = $this->ds($doc, $ref, 'Transforms');
        $this->ds($doc, $tr, 'Transform')->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
        $this->ds($doc, $tr, 'Transform')->setAttribute('Algorithm', self::C14N);
        $this->ds($doc, $ref, 'DigestMethod')->setAttribute('Algorithm', $digestUri);
        $this->ds($doc, $ref, 'DigestValue', $digest);

        $assinatura = '';
        if (! openssl_sign($si->C14N(false, false), $assinatura, $keyPem, $sslAlg)) {
            throw new \RuntimeException('Falha ao assinar a DPS com o certificado.');
        }
        $this->ds($doc, $sig, 'SignatureValue', base64_encode($assinatura));
        $ki = $this->ds($doc, $sig, 'KeyInfo');
        $x509 = $this->ds($doc, $ki, 'X509Data');
        $this->ds($doc, $x509, 'X509Certificate', $this->certBase64($certPem));

        return $doc;
    }

    /** Verifica digest e assinatura (usado nos testes e para conferência). */
    public function verificar(DOMDocument $doc, string $tagAssinada): bool
    {
        $alvo = $doc->getElementsByTagName($tagAssinada)->item(0);
        $sig = $doc->getElementsByTagNameNS(self::DS, 'Signature')->item(0);
        if (! $alvo || ! $sig) return false;
        $si = $sig->getElementsByTagNameNS(self::DS, 'SignedInfo')->item(0);
        $sha256 = str_contains($si->getElementsByTagNameNS(self::DS, 'SignatureMethod')->item(0)->getAttribute('Algorithm'), 'sha256');

        // digest calculado sem a Signature (transform enveloped)
        $clone = new DOMDocument();
        $clone->loadXML($doc->saveXML());
        $s2 = $clone->getElementsByTagNameNS(self::DS, 'Signature')->item(0);
        $s2->parentNode->removeChild($s2);
        $alvo2 = $clone->getElementsByTagName($tagAssinada)->item(0);
        $digest = base64_encode(hash($sha256 ? 'sha256' : 'sha1', $alvo2->C14N(false, false), true));
        if ($digest !== $si->getElementsByTagNameNS(self::DS, 'DigestValue')->item(0)->textContent) return false;

        $cert = "-----BEGIN CERTIFICATE-----\n" . chunk_split($sig->getElementsByTagNameNS(self::DS, 'X509Certificate')->item(0)->textContent, 64, "\n") . "-----END CERTIFICATE-----\n";
        $valor = base64_decode($sig->getElementsByTagNameNS(self::DS, 'SignatureValue')->item(0)->textContent);
        return openssl_verify($si->C14N(false, false), $valor, $cert, $sha256 ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1) === 1;
    }

    private function ds(DOMDocument $d, DOMElement $pai, string $nome, ?string $valor = null): DOMElement
    {
        $e = $d->createElementNS(self::DS, $nome);
        if ($valor !== null) $e->appendChild($d->createTextNode($valor));
        $pai->appendChild($e);
        return $e;
    }

    private function certBase64(string $pem): string
    {
        return preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem);
    }
}
