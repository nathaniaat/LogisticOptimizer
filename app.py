from flask import Flask, render_template, request, jsonify
from services.graph_service import GraphService
from services.aco_service import ACOService
from services.ga_service import GAService

app = Flask(__name__)

default_edges = [
    ["Jakarta",     "Cirebon",     258],
    ["Cirebon",     "Semarang",    237],
    ["Semarang",    "Solo",        100],
    ["Solo",        "Surabaya",    260],
    ["Surabaya",    "Malang",       90],
    ["Semarang",    "Yogyakarta",   65],
    ["Yogyakarta",  "Solo",         60],
    ["Jakarta",     "Bandung",     150],
    ["Bandung",     "Tasikmalaya", 105],
    ["Tasikmalaya", "Yogyakarta",  200],
]

default_trucks = {
    1: {"name": "Truk JKT-1", "origin": "Jakarta",  "max_weight": 1000, "max_volume": 9000000},
    2: {"name": "Truk JKT-2", "origin": "Jakarta",  "max_weight": 1000, "max_volume": 9000000},
    3: {"name": "Truk SBY-1", "origin": "Surabaya", "max_weight": 1000, "max_volume": 9000000},
    4: {"name": "Truk SBY-2", "origin": "Surabaya", "max_weight": 1000, "max_volume": 9000000},
}

default_items = {
    1:  {"name": "Kulkas 2 Pintu",       "weight": 80,  "origin": "Jakarta",  "destination": "Semarang",    "volume": 350000},
    2:  {"name": "TV LED 55 inch",       "weight": 45,  "origin": "Jakarta",  "destination": "Bandung",     "volume": 900000},
    3:  {"name": "Pompa Air Industri",   "weight": 320, "origin": "Surabaya", "destination": "Semarang",    "volume": 1800000},
    4:  {"name": "Generator Set",        "weight": 750, "origin": "Surabaya", "destination": "Malang",      "volume": 2800000},
    5:  {"name": "Sofa 3-Seater",        "weight": 110, "origin": "Jakarta",  "destination": "Yogyakarta",  "volume": 2000000},
    6:  {"name": "Lemari Pakaian",       "weight": 180, "origin": "Surabaya", "destination": "Solo",        "volume": 2400000},
    7:  {"name": "Karton Mie Instan",    "weight": 60,  "origin": "Jakarta",  "destination": "Semarang",    "volume": 120000},
    8:  {"name": "Karton Minuman",       "weight": 90,  "origin": "Jakarta",  "destination": "Cirebon",     "volume": 180000},
    9:  {"name": "Bal Kain Batik",       "weight": 200, "origin": "Surabaya", "destination": "Solo",        "volume": 600000},
    10: {"name": "Bal Denim",            "weight": 250, "origin": "Jakarta",  "destination": "Yogyakarta",  "volume": 750000},
    11: {"name": "Box Bearing",          "weight": 160, "origin": "Surabaya", "destination": "Jakarta",     "volume": 700000},
    12: {"name": "Box Elektronik",       "weight": 480, "origin": "Surabaya", "destination": "Malang",      "volume": 900000},
    13: {"name": "Kompor Gas Bulk",      "weight": 130, "origin": "Jakarta",  "destination": "Bandung",     "volume": 500000},
    14: {"name": "Tinta Printing",       "weight": 55,  "origin": "Jakarta",  "destination": "Cirebon",     "volume": 90000},
    15: {"name": "Mesin CNC Mini",       "weight": 500, "origin": "Surabaya", "destination": "Jakarta",     "volume": 2200000},
    16: {"name": "Spare Part Motor",     "weight": 150, "origin": "Jakarta",  "destination": "Yogyakarta",  "volume": 300000},
    17: {"name": "Panel Surya",          "weight": 200, "origin": "Jakarta",  "destination": "Semarang",    "volume": 800000},
    18: {"name": "Kabel Listrik Rol",    "weight": 80,  "origin": "Jakarta",  "destination": "Tasikmalaya", "volume": 80000},
    19: {"name": "Cat Tembok Drum",      "weight": 300, "origin": "Jakarta",  "destination": "Cirebon",     "volume": 400000},
    20: {"name": "Pipa PVC Bundel",      "weight": 250, "origin": "Surabaya", "destination": "Solo",        "volume": 900000},
}

@app.route('/')
def index():
    graph = GraphService(default_edges)
    cities = graph.get_cities()
    edges = graph.get_edges()
    dist_matrix = graph.get_distance_matrix()

    items_with_category = []
    for _id, item in default_items.items():
        cat = GAService.get_category(item['volume'])
        it = item.copy()
        it['category'] = cat
        it['cat_label'] = GAService.get_category_label(cat)
        it['id'] = _id
        items_with_category.append(it)

    return render_template('index.html', 
                           cities=cities, 
                           edges=edges, 
                           dist_matrix=dist_matrix, 
                           items_with_category=items_with_category)

@app.route('/optimize', methods=['POST'])
def optimize():
    data = request.json
    input_items = data.get('items', [])
    ga_params = data.get('ga', {})
    aco_params = data.get('aco', {})
    
    items = {}
    for idx, item in enumerate(input_items):
        _id = idx + 1
        volume = int(item['length'] * item['width'] * item['height'])
        items[_id] = {
            'name': item['name'],
            'weight': float(item['weight']),
            'origin': item['origin'],
            'destination': item['destination'],
            'volume': volume,
            'category': GAService.get_category(volume)
        }
        
    if not items:
        return jsonify({'success': False, 'message': 'Tidak ada barang.'}), 400
        
    graph = GraphService(default_edges)
    
    aco = ACOService(
        graph.get_distance_matrix(),
        num_ants=int(aco_params.get('num_ants', 5)),
        max_iter=int(aco_params.get('max_iter', 15)),
        alpha=float(aco_params.get('alpha', 1.0)),
        beta=float(aco_params.get('beta', 2.0)),
        rho=float(aco_params.get('rho', 0.5)),
        q0=float(aco_params.get('q0', 0.7)),
        Q=float(aco_params.get('Q', 100)),
        init_pheromone=float(aco_params.get('init_pheromone', 0.1))
    )
    
    ga = GAService(
        items,
        default_trucks,
        graph,
        aco,
        cost_per_liter=6800,
        km_per_liter=4,
        pop_size=int(ga_params.get('pop_size', 50)),
        elite_size=int(ga_params.get('elite_size', 2)),
        pc=float(ga_params.get('pc', 0.8)),
        pm=float(ga_params.get('pm', 0.2)),
        n_iter=int(ga_params.get('n_iter', 100))
    )
    
    result = ga.run()
    
    formatted_items = {}
    for _id, item in items.items():
        it = item.copy()
        it['id'] = _id
        it['cat_label'] = GAService.get_category_label(item['category'])
        formatted_items[_id] = it
        
    return jsonify({
        'success': True,
        'data': result,
        'items': formatted_items
    })

if __name__ == '__main__':
    app.run(debug=True)
