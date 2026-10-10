# Essência — gestão de perfumaria

Esta pasta é a `public_html` do site (Hostinger > Avançado > Git).

- `index.html`: o programa
- `api.php`: API de sincronização (MySQL)
- `database.php`: **não vem aqui** (guarda a senha do banco). Crie-o uma vez no servidor, pelo Gerenciador de Arquivos.

## Proteção contra cópia

- `LICENSE`: software proprietário, todos os direitos reservados.
- `index.html`: só abre nos domínios listados em `LIC.hosts` (início do script). Ao trocar de domínio, edite essa lista.
- `api.php`: recusa chamadas vindas de outros domínios.
- `catalogo.php` / `.htaccess`: bloqueiam o uso das fotos em outros sites e o download de arquivos internos (.git, .sql, .zip, database.php).
- Mantenha o repositório do GitHub **privado**.
