# Shared UI Components

Composants d'interface utilisateur partages.

## Structure prevue

```
UI/
├── Components/
│   ├── Button.php
│   ├── Modal.php
│   ├── DataTable.php
│   ├── Form/
│   │   ├── Input.php
│   │   ├── Select.php
│   │   └── Textarea.php
│   └── Layout/
│       ├── Header.php
│       ├── Sidebar.php
│       └── Footer.php
├── Traits/
│   └── RendersTailwind.php
└── README.md
```

## Concept

Les composants sont des classes PHP qui generent du HTML avec Tailwind CSS.

```php
use KDocs\Shared\UI\Components\Button;
use KDocs\Shared\UI\Components\Modal;

// Bouton
echo Button::primary('Sauvegarder', ['type' => 'submit']);
echo Button::danger('Supprimer', ['onclick' => 'confirm()']);

// Modal
echo Modal::open('modal-edit', 'Modifier document');
echo Modal::body($content);
echo Modal::close();
```

## Coherence visuelle

Tous les composants utilisent la meme palette Tailwind :
- Primary: blue-600
- Success: green-600
- Danger: red-600
- Warning: yellow-600

## Statut

**A faire** - Phase de conception
