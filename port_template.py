import sys

content = open('resources/views/welcome.blade.php', 'r', encoding='utf-8').read()

content = content.replace('@json($cities)', '{{ cities | tojson | safe }}')
content = content.replace('@json($edges)', '{{ edges | tojson | safe }}')
content = content.replace('@json($distMatrix)', '{{ dist_matrix | tojson | safe }}')
content = content.replace('@json($itemsWithCategory)', '{{ items_with_category | tojson | safe }}')

content = content.replace('<meta name="csrf-token" content="{{ csrf_token() }}">', '')
content = content.replace("'X-CSRF-TOKEN': document.querySelector('meta[name=\"csrf-token\"]').getAttribute('content'),", '')

with open('templates/index.html', 'w', encoding='utf-8') as f:
    f.write(content)
