@extends('adminlte::page')

@section('title', 'Reporte Operacional')

@section('content_header')
    <h1>Reporte Operacional</h1>
@stop

@section('content')
    <div class="container-fluid">
        {{-- Formulario de filtros --}}
        <form method="GET" action="{{ route('admin.reportes.operacional') }}" class="mb-4">
            <div class="row">
                <div class="col-md-2">
                    <label>Fecha inicio</label>
                    <input type="date" name="start" value="{{ request('start') }}" class="form-control">
                </div>
                <div class="col-md-2">
                    <label>Fecha fin</label>
                    <input type="date" name="end" value="{{ request('end') }}" class="form-control">
                </div>

                {{-- Select Negocio --}}
                <div class="col-md-2">
                    <label class="form-label">Negocio</label>
                    <select name="business_id" class="form-control">
                        <option value="">Todos</option>
                        @foreach ($businesses as $b)
                            <option value="{{ $b->busines_id }}" {{ $business_id == $b->busines_id ? 'selected' : '' }}>
                                {{ $b->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Select Domiciliario --}}
                <div class="col-md-2">
                    <label class="form-label">Domiciliario</label>
                    <select name="domiciliary_id" class="form-control">
                        <option value="">Todos</option>
                        @foreach ($domiciliaries as $d)
                            <option value="{{ $d->domiciliary_id }}"
                                {{ $domiciliary_id == $d->domiciliary_id ? 'selected' : '' }}>
                                {{ $d->user->name ?? 'Sin nombre' }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100 mt-2">Filtrar</button>
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <a href="{{ route('admin.reportes.operacional.export', ['start' => request('start'), 'end' => request('end')]) }}" class="btn btn-success btn-block">
                        <i class="fas fa-file-excel"></i> Exportar Excel
                    </a>
                </div>
            </div>
        </form>


        {{-- Cards principales --}}
        <div class="row">
            <div class="col-md-3">
                <div class="small-box bg-danger">
                    <div class="inner text-center">
                        <h3>{{ $tasaCancelaciones }}%</h3>
                        <p>Tasa de cancelaciones</p>
                    </div>
                    <div class="icon"><i class="fas fa-times-circle"></i></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="small-box bg-success">
                    <div class="inner text-center">
                        <h3>{{ $satisfaccionNegocios }}</h3>
                        <p>Satisfacción de negocios</p>
                    </div>
                    <div class="icon"><i class="fas fa-store"></i></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="small-box bg-info">
                    <div class="inner text-center">
                        <h3>{{ $satisfaccionDomiciliarios }}</h3>
                        <p>Satisfacción de domiciliarios</p>
                    </div>
                    <div class="icon"><i class="fas fa-motorcycle"></i></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="small-box bg-warning">
                    <div class="inner text-center">
                        <h3>{{ $disponibles }}/{{ $totalDomiciliarios }}</h3>
                        <p>Domiciliarios disponibles</p>
                    </div>
                    <div class="icon"><i class="fas fa-user-check"></i></div>
                </div>
            </div>
        </div>

        {{-- Tabs --}}
        <ul class="nav nav-tabs mt-4" id="myTab" role="tablist">
            <li class="nav-item" role="presentation">
                <a class="nav-link active" id="disponibilidad-tab" data-toggle="tab" href="#disponibilidad" role="tab"
                    aria-controls="disponibilidad">
                    Disponibilidad
                </a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link" id="cobertura-tab" data-toggle="tab" href="#cobertura" role="tab"
                    aria-controls="cobertura">
                    Cobertura geográfica
                </a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link" id="reviews-tab" data-toggle="tab" href="#reviews" role="tab"
                    aria-controls="reviews">
                    Reseñas
                </a>
            </li>

        </ul>

        <div class="tab-content border p-3" id="myTabContent">
            {{-- Chart Disponibilidad --}}
            <div class="tab-pane fade show active" id="disponibilidad" role="tabpanel" aria-labelledby="disponibilidad-tab">
                <div class="row">
                    <div class="col-md-4 offset-md-4">
                        <canvas id="disponibilidadChart" height="200"></canvas>
                    </div>
                </div>
            </div>

            {{-- Mapa + tablas --}}
            <div class="tab-pane fade" id="cobertura" role="tabpanel" aria-labelledby="cobertura-tab">
                <div class="row">
                    <div class="col-md-6">
                        <div id="map" style="height:300px;"></div>
                    </div>

                    <div class="col-md-6">
                        <div class="row">
                            <div class="col-12">
                                <h6>Negocios (coords)</h6>
                                <table class="table table-sm table-striped">
                                    <thead>
                                        <tr>
                                            <th>Negocio</th>
                                            <th>Lat</th>
                                            <th>Lng</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($businesses as $b)
                                            <tr>
                                                <td>{{ $b->name }}</td>
                                                <td>{{ $b->latitude }}</td>
                                                <td>{{ $b->longitude }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div class="col-12">
                                <h6>Domiciliarios</h6>
                                <table class="table table-sm table-striped">
                                    <thead>
                                        <tr>
                                            <th>Domiciliario</th>
                                            <th>ID</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($domiciliaries as $d)
                                            <tr>
                                                <td>{{ $d->user->name ?? 'Sin nombre' }}</td>
                                                <td>{{ $d->domiciliary_id }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Tab Reseñas --}}
            <div class="tab-pane fade" id="reviews" role="tabpanel" aria-labelledby="reviews-tab">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Reseñas de Negocios</h6>
                        <table class="table table-hover table-striped table-bordered align-middle shadow-sm rounded">
                            <thead>
                                <tr>
                                    <th>Negocio</th>
                                    <th>Comprador</th>
                                    <th>Calificación</th>
                                    <th>Comentario</th>
                                    <th>Fecha</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($businessReviewsList as $r)
                                    <tr>
                                        <td>{{ $r->business->name ?? 'N/A' }}</td>
                                        <td>{{ $r->buyer->name ?? 'N/A' }}</td>
                                        <td>{{ $r->qualification }}</td>
                                        <td>{{ $r->comment }}</td>
                                        <td>{{ $r->created_at }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6>Reseñas de Domiciliarios</h6>
                        <table class="table table-hover table-striped table-bordered align-middle shadow-sm rounded">
                            <thead>
                                <tr>
                                    <th>Domiciliario</th>
                                    <th>Comprador</th>
                                    <th>Calificación</th>
                                    <th>Comentario</th>
                                    <th>Fecha</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($domiciliaryReviewsList as $r)
                                    <tr>
                                        <td>{{ $r->domiciliary->user->name ?? 'N/A' }}</td>
                                        <td>{{ $r->buyer?->user?->name ?? 'N/A' }}</td>
                                        <td>{{ $r->qualification }}</td>
                                        <td>{{ $r->comment }}</td>
                                        <td>{{ $r->created_at }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>

    @stop

    @section('css')
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.3/dist/leaflet.css" />
    @stop

    @section('js')
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script src="https://unpkg.com/leaflet@1.9.3/dist/leaflet.js"></script>

        <script>
            // Chart disponibilidad
            const ctx = document.getElementById('disponibilidadChart');
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Disponibles', 'No disponibles'],
                    datasets: [{
                        data: [{{ $disponibles }}, {{ $totalDomiciliarios - $disponibles }}],
                        backgroundColor: ['#28a745', '#dc3545'],
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });

            // Mapa con marcadores y fitBounds
            const pedidos = @json($coordenadas);
            const map = L.map('map');
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

            const bounds = [];
            pedidos.forEach(p => {
                if (p.lat && p.lng) {
                    const marker = L.marker([p.lat, p.lng]).addTo(map)
                        .bindPopup(`<strong>${p.nombre??''}</strong>`);
                    bounds.push([p.lat, p.lng]);
                }
            });

            if (bounds.length) {
                map.fitBounds(bounds);
            } else {
                map.setView([4.6, -74.08], 11);
            }

            // Distribución negocios
            const businessLabels = {!! json_encode(array_keys($distBusiness)) !!};
            const businessData = {!! json_encode(array_values($distBusiness)) !!};
            new Chart(document.getElementById('businessDistChart'), {
                type: 'bar',
                data: {
                    labels: businessLabels,
                    datasets: [{
                        label: 'Número de reseñas',
                        data: businessData,
                        backgroundColor: '#28a745'
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            stepSize: 1
                        }
                    }
                }
            });

            // Distribución domiciliarios
            const domLabels = {!! json_encode(array_keys($distDomiciliary)) !!};
            const domData = {!! json_encode(array_values($distDomiciliary)) !!};
            new Chart(document.getElementById('domiciliaryDistChart'), {
                type: 'bar',
                data: {
                    labels: domLabels,
                    datasets: [{
                        label: 'Número de reseñas',
                        data: domData,
                        backgroundColor: '#17a2b8'
                    }]

                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            stepSize: 1
                        }
                    }
                }
            });
        </script>
    @stop
