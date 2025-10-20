# DS - Créditos por Produtos para TeraWallet

Concede créditos na carteira TeraWallet automaticamente quando pedidos são concluídos e auto-completa pedidos virtuais elegíveis que concedem créditos.

## Descrição

Este plugin WordPress adiciona funcionalidade ao WooCommerce para conceder créditos TeraWallet aos clientes quando eles compram produtos específicos. Além disso, pedidos compostos exclusivamente por produtos virtuais que concedem créditos (valor >= 1) são automaticamente marcados como concluídos após o pagamento.

### Recursos Principais

- ✅ **Concessão Automática de Créditos**: Adiciona créditos à carteira TeraWallet do cliente quando o pedido é concluído
- ✅ **Auto-Complete de Pedidos Virtuais**: Completa automaticamente pedidos com produtos virtuais elegíveis após confirmação de pagamento
- ✅ **Campo Personalizado no Produto**: Interface simples para configurar a quantidade de créditos por produto
- ✅ **Compatível com HPOS**: Totalmente compatível com High-Performance Order Storage do WooCommerce
- ✅ **Suporte a Quantidade**: Calcula créditos multiplicando o valor configurado pela quantidade comprada
- ✅ **Prevenção de Duplicação**: Sistema de flag para evitar concessão duplicada de créditos
- ✅ **Notas de Pedido**: Adiciona notas automáticas documentando a concessão de créditos

## Requisitos

- WordPress 5.0 ou superior
- WooCommerce 5.0 ou superior
- TeraWallet - WooCommerce Wallet (ativo e configurado)
- PHP 7.4 ou superior

## Instalação

1. Faça o upload da pasta `dsi-wc-credits` para o diretório `/wp-content/plugins/`
2. Ative o plugin através do menu 'Plugins' no WordPress
3. Certifique-se de que WooCommerce e TeraWallet estão instalados e ativos

## Configuração

### Configurando Créditos em Produtos

1. Acesse **Produtos** > **Adicionar Novo** (ou edite um produto existente)
2. Na aba **Geral**, localize o campo **"Créditos TeraWallet a conceder"**
3. Insira a quantidade de créditos que este produto deve conceder (ex: 10.50)
4. Salve o produto

### Critérios para Auto-Complete

Para que um pedido seja automaticamente concluído após o pagamento, **TODOS** os produtos do pedido devem atender aos seguintes critérios:

- Ser um produto virtual
- Ter créditos configurados com valor >= 1

Se qualquer produto não atender esses requisitos, o pedido seguirá o fluxo normal do WooCommerce.

## Como Funciona

### Fluxo de Concessão de Créditos

1. Cliente finaliza a compra de um produto configurado com créditos
2. Pagamento é confirmado
3. Pedido alcança o status "Concluído" (automático ou manual)
4. Plugin calcula: `créditos_por_produto × quantidade_comprada`
5. Créditos são adicionados à carteira TeraWallet do cliente
6. Nota é adicionada ao pedido documentando a transação
7. Flag `_dsi_credits_awarded` previne duplicação

### Fluxo de Auto-Complete

1. Pagamento do pedido é confirmado (`woocommerce_payment_complete`)
2. Plugin verifica se todos os produtos são virtuais e concedem créditos >= 1
3. Se sim, pedido é automaticamente marcado como "Concluído"
4. Créditos são então concedidos pelo fluxo acima

## Exemplos de Uso

### Exemplo 1: Recarga de Créditos
```
Produto: "Recarga de 50 Créditos"
Tipo: Virtual
Créditos TeraWallet: 50
Preço: R$ 45,00

Resultado: Cliente recebe 50 créditos + pedido é auto-completado
```

### Exemplo 2: Produto Físico com Bônus
```
Produto: "Camiseta Premium"
Tipo: Físico
Créditos TeraWallet: 5
Preço: R$ 89,90

Resultado: Cliente recebe 5 créditos quando pedido for enviado/concluído
```

### Exemplo 3: Compra de Múltiplas Unidades
```
Produto: "Pacote de 10 Créditos"
Quantidade: 3 unidades
Créditos por unidade: 10

Resultado: Cliente recebe 30 créditos (10 × 3)
```

## Hooks e Filtros

O plugin utiliza os seguintes hooks do WooCommerce:

- `woocommerce_order_status_completed` - Concede créditos quando pedido é concluído
- `woocommerce_payment_complete` - Verifica elegibilidade para auto-complete
- `woocommerce_product_options_general_product_data` - Adiciona campo no produto
- `woocommerce_process_product_meta` - Salva configuração de créditos

## Compatibilidade

- ✅ WooCommerce HPOS (High-Performance Order Storage)
- ✅ WooCommerce 5.0 - 8.2+
- ✅ WordPress Multisite
- ✅ Produtos Simples e Variáveis
- ✅ Gateways de Pagamento padrão do WooCommerce

## Solução de Problemas

### Créditos não estão sendo concedidos

- Verifique se o TeraWallet está ativo e configurado
- Confirme que o pedido está com status "Concluído"
- Verifique se o cliente tem uma conta registrada (user_id válido)
- Confira se o produto tem um valor de créditos configurado

### Pedido não está auto-completando

- Certifique-se de que TODOS os produtos são virtuais
- Verifique se todos os produtos têm créditos >= 1 configurados
- Confirme que o pagamento foi processado com sucesso

### Créditos duplicados

O plugin previne automaticamente duplicação através da meta `_dsi_credits_awarded`. Se ocorrer, pode ser um conflito com outro plugin.

## Changelog

### 2.0.0
- Compatibilidade com HPOS declarada
- Código refatorado para singleton pattern
- Melhorias na validação de dados
- Sistema de prevenção de duplicação otimizado

## Suporte

Para suporte e mais informações:
- Site: [https://dsantosinfo.com.br](https://dsantosinfo.com.br)
- Documentação: [https://backgamon.dsantosinfo.com.br](https://dsantosinfo.com.br)

## Licença

Este plugin é licenciado sob GPLv2 ou posterior.

## Créditos

Desenvolvido por **DSantos Info**