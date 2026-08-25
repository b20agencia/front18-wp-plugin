=== Front18 Security Integration ===
Contributors: front18
Donate link: https://front18.com
Tags: security, sdk, front18
Requires at least: 5.0
Tested up to: 6.7
Stable tag: 1.5.2
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

= 1.5.2 =
* Telemetria: ao salvar, o plugin informa ao Front18 como a protecao foi configurada e o uso da IA (sem enviar imagens), para o suporte ter visibilidade.

= 1.5.1 =
* IA: bem menos falso positivo. So marca quando a classe dominante do modelo e explicita (Porn/Hentai) e passa do limiar. Novo controle de "Rigor" (Só o óbvio / Equilibrado / Mais sensivel).

= 1.5.0 =
* IA focada em nudez explicita (ignora biquini/sensual), mais rapida (miniatura pequena), nao trava ao trocar de aba (audio silencioso) e com modo automatico: caixas "pre-selecionar" e "salvar sozinho ao terminar" + mini-relatorio ao fim.

= 1.4.4 =
* IA de sugestao: barra de progresso visual enquanto analisa, e texto do aviso atualizado (a IA agora pre-seleciona; voce revisa e desmarca).

= 1.4.3 =
* IA de sugestao: agora pre-seleciona as imagens marcadas como possivel +18 (voce so desmarca as que discordar e salva) e analisa a biblioteca inteira, nao so a pagina do preview.

= 1.4.2 =
* IA de sugestao: correcao — todas as imagens davam o mesmo score (tf carregado em duplicidade). Agora carrega so o nsfwjs (tf embutido); tf.min.js saiu do pacote (~1,5MB menor).

= 1.4.1 =
* IA de sugestao: correcao — a analise marcava 0. Agora carrega cada imagem de forma fresca (analisa a pagina inteira, nao so o visivel) e com crossOrigin, limiar mais sensivel, e relatorio de lidas/erro/sugeridas (scores no Console F12).

= 1.4.0 =
* IA de sugestao de conteudo explicito (em teste): botao "Analisar imagens" na Selecao de Midia roda um modelo de deteccao de nudez no proprio navegador — as imagens nao saem do servidor. Marca "possivel +18"; e um auxilio, voce revisa e confirma. Modelo embarcado, carregado sob demanda.
* Avisos do WordPress agora aparecem no topo da pagina (padrao h1 + wp-header-end), nao no meio do painel.

= 1.3.2 =
* Ajuste visual: a logo da marca ganhou um fundo escuro no cabecalho do painel, para aparecer com contraste no novo visual claro.

= 1.3.1 =
* Painel repaginado: visual claro, amplo e moderno (app-bar com a logo, abas em sublinhado, cartoes claros), no lugar da caixa escura estreita.
* Enxugado: sairam o seletor de posts include/exclude, a lista de shortcodes e a dependencia externa do Select2 (CDN). A protecao vem da Selecao de Midia e do painel.

= 1.3.0 =
* Selecao de midia no WordPress: nova aba "Selecao de Midia" com uma grade que le a sua propria Biblioteca (miniaturas, busca, filtro por data e por pasta). A escolha do que e protegido passa a ser feita dentro do wp-admin, sem enviar as imagens para fora do servidor.
* Ao salvar, a selecao e empurrada para o Front18 (sync reverso autenticado) e aplicada no site na hora; o gerenciador de midia do painel Front18 vira espelho somente-leitura.
* Painel do plugin repaginado em abas (Conexao, Protecao, Selecao de Midia, Avancado).

= 1.0.2 =
* Feature: Destravamento do payload do webhook para suporte estendido a chaves dinâmicas do SDK (Integração Facial, DPO unificado).

= 1.0.1 =
* Correção vital: Estratégia de cache do frontend para evitar loops de bypass e proteger a cdn do SaaS Central.

= 1.0.0 =
* Estruturação inicial do repositório profissional.
