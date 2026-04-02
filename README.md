# 🛡️ Front18 Security - Guia de Instalação Rápida

O Front18 Security é uma camada agnóstica de proteção visual (Anti-Flicker) para o WordPress. Ele permite a injeção segura e assíncrona do seu SDK de Produto em qualquer site WP, bloqueando a renderização da tela instantaneamente sem penalizar o SEO (Zero CLS, LCP Friendly).

---

## 📦 1. Como Instalar no WordPress

Nenhuma habilidade em código é necessária para instalar o Front18 em um site novo.

1. **Faça o Download do Plugin:**
   - Compacte a pasta inteira onde os arquivos estão localizados em um arquivo `.zip` (Ex: `front18-wp-plugin.zip`).
2. **Suba para o WordPress:**
   - Acesse o painel administrativo (`wp-admin`) do site.
   - Navegue pelo menu lateral esquerdo até **Plugins > Adicionar Novo (Add New)**.
   - Clique no botão azul **Enviar Plugin (Upload Plugin)** no topo da tela.
   - Selecione o arquivo `front18-wp-plugin.zip` do seu computador e clique em instalar.
3. **Ative o Plugin:**
   - Ao concluir a extração, clique em **Ativar Plugin**.
   - *(Atenção: O nosso motor de segurança impedirá a ativação em hospedagens arcaicas usando PHP inferior à versão 7.4. Mas fique tranquilo, se falhar, o lojista será avisado gentilmente que precisa atualizar o servidor, sem causar Queda (Fatal Error) na loja!)*

---

## ⚙️ 2. Como Configurar e Vincular sua Conta

Logo após a ativação, um novo botão brilhante aparecerá flutuando sob a aba "Configurações", escrito **Front18**. Clique nele.

### Passo A: Conectar a API e Ativar
1. No primeiro bloco na tela (Configuração Principal), ative o toggle **"Ativar Front18"**.
2. No campo **SaaS API Key / Client ID**, cole a Chave Exclusiva gerada e entregue pelo Painel de Assinantes do Front18.
   - *(Você notará uma Placa Vermelha enorme e intransponível alertando que o plugin não protegerá nada até que uma Chave válida preencha esse campo).*
3. Ao preencher, clique no botão azul de Salvar no rodapé. A placa ficará instantaneamente verde (**🟢 Protegendo este site**).

### Passo B: Definir "Onde" Rola a Proteção
No cartão **Onde aplicar a Proteção?**, você define o escopo do SaaS:
- **Força Bruta Global:** Bloqueia automaticamente o site de cabo a rabo na escuridão, incluindo URLS dinâmicas de buscas de produtos e autores.
- **Página Inicial:** Mantém o restante da loja solto e restringe o escudo apenas à Home ("Vitrine mestre").
- **Tipos de Posts (Granular):** Acenda os toggles para blindar em massa entidades nativas: *Posts*, *Páginas*, e se você tiver e-commerce instalado, o glorioso escopo *Produtos (WooCommerce)* acenderá automaticamente.

### Passo C: Override Cirúrgico (Busca Ajax/Blacklist)
Se você decidiu travar o **Site Inteiro (Escopo Global)**, mas não quer trancar usuários que desejam ler a sua **Política de Privacidade** ou **FAQ** corporativo...
1. Desça até o cartão de finalidades: **"Override Cirúrgico"**.
2. Na caixa verde de **Liberar IDs Seguros**, clique e digite "Privacidade" (Mínimo 3 letras).
3. O Smart Search do WordPress fará uma busca super-rápida. Selecione o artigo. Pronto: Regra quebrada. A página de privacidade sobrepõe as amarras do Front18.

*(Ps: O mesmo sistema vale via Caixa Vermelha (Blacklist), excelente para blindar manualmente de 1 em 1 apenas aqueles "Cinco Artigos Exclusivos" do Fórum sem blindar o Global).*

---

## 📝 3. O "Pulo do Gato": Edição Lateral Soberana (Produto Nível 3)
Imagine que você, redator, publicou um "Tutorial Para Adultos" picante agora à tarde... e você não quer interromper o fluxo para ir até às "Opções de Plugin" escanear artigos. 
Nós pensamos no fluxo de vida do editor.

1. Dentro da **tela de Escrever/Editar Posts e Páginas** normal do WordPress do seu cliente...
2. Olhe para a extrema Direita da Tela. Logo abaixo das publicações habituais.
3. Você verá uma MetaBox nativa: **🛡️ Defesa Front18**.
4. Basta alterar o Select de Automático para **🔴 Forçar Proteção** e dar Update.
Acabou! Mesmo que o seu Painel inteiro do "Passo 2" estivesse desligado, essa página sozinha blindará quem acessar com prioridade máxima.

---

## 🔬 4. Suporte a Desenvolvedores (Modo Hacker)
Seus devs da empresa precisam apontar em homologação (localhost) pra testar o SDK sem precisar abrir o editor de código PHP do cliente final?
- Expanda a Sanfona no final da página de Opções **"Painel de Desenvolvedor e Integração"**.
- Lá você quebra a URL Mestre, o Objeto JS que acende as Regras (Ex: var ReactName / Front18), e principalmente:
Ligue o **Modo Telemetria**. Isso envia de forma síncrona o Pipeline do Edge Computing do Cloudflare em formato de Milissegundos no seu `Console Global F12`, detectando de forma limpa onde o Boot falhou em problemas de Suporte Nível 3, distinguindo uma perca de pacote de conexão em redes 3G ("`CDN inalcançável`") de um travamento em Exception fatal dentro do seu pr&oacute;prio servidor V8 ("`SDK falhou`").
