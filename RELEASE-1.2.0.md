# Front18 Security Integration 1.2.0

**Atualização de segurança. Recomendada para todas as instalações.**

Esta versão corrige uma falha que permitia desativar a proteção de idade de um site
usando apenas informação pública, e conserta o canal de atualização automática, que
estava impedido de entregar novas versões.

---

## Correção de segurança

### A chave de API podia alterar as regras de proteção

O plugin usa dois credenciais distintos:

- a **API Key**, que o SDK precisa ler no navegador do visitante e que, por isso, é
  impressa no HTML de toda página protegida — ou seja, é pública por natureza;
- o **webhook secret**, privado, usado pelo painel Front18 para enviar configuração
  ao site.

Até a 1.1.9, enquanto o webhook secret ainda não tivesse chegado ao site, o endpoint
`/wp-json/front18/v1/sync` aceitava a API Key no lugar dele. Nessa janela, qualquer
pessoa que lesse o código-fonte de uma página do site podia:

- **desativar a proteção de idade**, reescrevendo as regras de aplicação; e
- **gravar um webhook secret próprio**, assumindo o canal de configuração de forma
  permanente — a partir daí, nem o painel legítimo conseguiria corrigir.

A janela existia apenas até a primeira sincronização bem-sucedida. Mas toda instalação
nova passava por ela, e é o momento em que o site está menos acompanhado.

**O que mudou.** O modo de inicialização continua existindo — sem ele, a primeira
sincronização nunca chegaria — porém agora aceita **exclusivamente** a entrega do
webhook secret, e ignora qualquer regra enviada no mesmo pedido. O painel reenvia a
sincronização com o segredo em seguida, de forma automática e transparente.

Nenhuma ação é necessária no site. A sincronização volta a funcionar sozinha na
próxima vez que o painel enviar configuração.

---

## Correção no canal de atualização

O manifesto `update.json` apontava a versão **1.1.8** enquanto o plugin publicado já
era a **1.1.9**. O Plugin Update Checker compara a versão instalada com a do manifesto:
com o manifesto atrás da versão publicada, **nenhuma atualização era oferecida** — nem
mesmo uma correção de segurança.

As três fontes de versão (cabeçalho do plugin, constante `FRONT18_VERSION` e o
manifesto) passam a ser conferidas automaticamente antes de cada publicação.

---

## Interface

Emojis removidos do painel administrativo e da documentação. Os indicadores de status
continuam legíveis — a cor já carregava o mesmo significado que o ícone.

---

## Compatibilidade

- WordPress 6.0 ou superior
- PHP 7.4 ou superior
- Atualização direta a partir de qualquer versão 1.1.x
- Não há mudança de banco de dados nem de configuração

## Atualização

Pelo painel do WordPress, em **Plugins > Atualizações**. Quem estiver na 1.1.8 ou
anterior verá a atualização normalmente; quem estiver na 1.1.9 passará a vê-la agora
que o manifesto foi corrigido.

Instalação manual: baixe o `front18-wp-plugin.zip` abaixo e envie em
**Plugins > Adicionar novo > Enviar plugin**.
