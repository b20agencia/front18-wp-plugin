=== Front18 Security Integration ===
Contributors: front18
Donate link: https://front18.com
Tags: security, sdk, front18
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.0.2
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

O motor de segurança definitiva (Anti-Flicker) para o SDK Front18. Isola e protege seu conteúdo sensível antes mesmo da página renderizar.

== Description ==

O **Front18 Security Integration** é a solução definitiva para evitar FOUC (Visual Flickering) e garantir total segurança ao carregar SDKs remotamente no frontend.
Ao usar essa integração, seu portal ou sistema rodando WordPress ganha proteção imediata sem penalizar o ranqueamento SEO ou causar degradação visual para os usuários.

= Benefícios e Funcionalidades =
* Bloqueio imediato para interações não autorizadas.
* Evita carregamento em falso do layout.
* Painel de controle integrado e minimalista.
* Extrema leveza, carregado de forma inteligente pelas APIs nativas do WordPress.

== Installation ==

1. Faça o upload do diretório `front18-wp-plugin` (ou do ZIP diretamente) para o seu painel do WordPress em Plugins -> Adicionar Novo -> Enviar Plugin.
2. Ative o plugin através do menu 'Plugins' no WordPress.
3. Configure as credenciais da API e regras de injeção diretamente no painel do administrador.

== Frequently Asked Questions ==

= O plugin quebra meu sistema de cache? =
Não. A integração foi desenhada para operar de modo assíncrono e isolado, garantindo alta compatibilidade.

== Changelog ==

= 1.0.2 =
* Feature: Destravamento do payload do webhook para suporte estendido a chaves dinâmicas do SDK (Integração Facial, DPO unificado).

= 1.0.1 =
* Correção vital: Estratégia de cache do frontend para evitar loops de bypass e proteger a cdn do SaaS Central.

= 1.0.0 =
* Estruturação inicial do repositório profissional.
