# The top menu

The links are listed in `config/menu.php` (order, labels, optional `group`). Nobody has to say which role sees which link:
`App\Support\Menu` reads the roles from each page's own route (`role:admin|super-admin` and so on) and shows the link only
to people who may open it. A super-admin (no school) sees only the `super.*` pages.
To add a page to the menu, add one line with its route name. It stays hidden from anyone who is not allowed to open it.
