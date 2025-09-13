@extends('adminlte::page')

@section('title', 'Dashboard')

@section('content_header')
    <h1>Dashboard</h1>
@stop

@section('content')
    <div class="container-fluid">
        <!-- Small boxes (estadísticas rápidas) -->
        <div class="row">
            @php
                $boxes = [
                    [
                        'count' => $domiciliariesCount,
                        'text' => 'Domiciliarios',
                        'icon' => 'fas fa-motorcycle',
                        'color' => 'info',
                        'url' => 'admin/domiciliarios',
                    ],
                    [
                        'count' => $buyersCount,
                        'text' => 'Compradores',
                        'icon' => 'fas fa-users',
                        'color' => 'success',
                        'url' => 'admin/compradores',
                    ],
                    [
                        'count' => $businessCount,
                        'text' => 'Negocios',
                        'icon' => 'fas fa-store',
                        'color' => 'warning',
                        'url' => 'admin/negocios',
                    ],
                    [
                        'count' => $ownersCount,
                        'text' => 'Propietarios',
                        'icon' => 'fas fa-user-tie',
                        'color' => 'danger',
                        'url' => 'admin/owners',
                    ],
                    [
                        'count' => $complexCount,
                        'text' => 'Complejos',
                        'icon' => 'fas fa-building',
                        'color' => 'primary',
                        'url' => 'admin/complejos',
                    ],
                    [
                        'count' => $productsCount,
                        'text' => 'Productos',
                        'icon' => 'fas fa-boxes',
                        'color' => 'primary',
                        'url' => 'admin/products',
                    ],
                ];
            @endphp

            @foreach ($boxes as $box)
                <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                    <div class="small-box bg-{{ $box['color'] }}">
                        <div class="inner">
                            <h3>{{ $box['count'] }}</h3>
                            <p>{{ $box['text'] }}</p>
                        </div>
                        <div class="icon"><i class="{{ $box['icon'] }}"></i></div>
                        <a href="{{ url($box['url']) }}" class="small-box-footer">
                            Más info <i class="fas fa-arrow-circle-right"></i>
                        </a>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="row">
            <!-- Usuarios Chart -->
            <div class="col-lg-4 col-md-6 col-sm-12 mb-3">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h6 class="card-title mb-0">Usuarios por tipo</h6>
                    </div>
                    <div class="card-body p-1">
                        <canvas id="usersChart" height="205"></canvas>
                    </div>
                </div>
            </div>


            <!-- Negocios Chart -->
            <div class="col-lg-4 col-md-6 col-sm-12 mb-3">
                <div class="card shadow-sm">
                    <div class="card-header bg-warning text-dark">
                        <h6 class="card-title mb-0">Negocios por tipo</h6>
                    </div>
                    <div class="card-body p-1">
                        <canvas id="businessChart" height="120"></canvas>
                    </div>
                </div>
            </div>

            <!-- Productos Chart -->
            <div class="col-lg-4 col-md-6 col-sm-12 mb-3">
                <div class="card shadow-sm">
                    <div class="card-header bg-success text-white">
                        <h6 class="card-title mb-0">Productos por categoría</h6>
                    </div>
                    <div class="card-body p-1">
                        <canvas id="productsChart" height="120"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Top negocios -->
            <div class="col-lg-6 col-md-12 mb-3">
                <div class="card shadow-sm">
                    <div class="card-header bg-info text-white">
                        <h6 class="card-title mb-0">Negocios con más pedidos</h6>
                    </div>
                    <div class="card-body p-2">
                        <ul class="list-group list-group-flush">
                            @foreach ($topBusinesses as $business)
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    {{ $business->name }}
                                    <span class="badge badge-primary badge-pill">{{ $business->orders_count }}
                                        pedidos</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Top productos -->
            <div class="col-lg-6 col-md-12 mb-3">
                <div class="card shadow-sm">
                    <div class="card-header bg-success text-white">
                        <h6 class="card-title mb-0">Top 5 productos más vendidos</h6>
                    </div>
                    <div class="card-body p-2">
                        <ul class="list-group list-group-flush">
                            @foreach ($topProducts as $product)
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    {{ $product->name }}
                                    <span class="badge badge-success badge-pill">{{ $product->sales_count }} ventas</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        </div>


    </div>
@stop

@section('adminlte_js')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

@php
    // Preparamos los arrays en PHP para evitar problemas de comas en JS
    $labelsProducts = [];
    $dataProducts = [];

    foreach($productCategories as $catId => $total){
        $labelsProducts[] = $categories[$catId] ?? 'Sin categoría';
        $dataProducts[] = $total;
    }
@endphp

<script>
    // Opciones reutilizables
    const chartOptionsSmall = {
        responsive: true,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    boxWidth: 15,
                    padding: 5
                }
            },
            tooltip: {
                mode: 'index',
                intersect: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 1,
                    font: {
                        size: 10
                    }
                }
            },
            x: {
                grid: {
                    display: false
                },
                ticks: {
                    font: {
                        size: 10
                    }
                }
            }
        }
    };

    // Usuarios - Doughnut
    new Chart(document.getElementById('usersChart'), {
        type: 'doughnut',
        data: {
            labels: ['Domiciliarios', 'Compradores', 'Propietarios'],
            datasets: [{
                data: [{{ $domiciliariesCount }}, {{ $buyersCount }}, {{ $ownersCount }}],
                backgroundColor: ['#17a2b8', '#28a745', '#dc3545'],
                borderColor: '#fff',
                borderWidth: 2
            }]
        },
        options: {
            ...chartOptionsSmall,
            maintainAspectRatio: false
        }
    });

    // Negocios - Barras
    new Chart(document.getElementById('businessChart'), {
        type: 'bar',
        data: {
            labels: ['Tienda', 'Farmacia', 'Otro'],
            datasets: [{
                label: 'Negocios',
                data: [
                    {{ $businessTypes[1] ?? 0 }},
                    {{ $businessTypes[2] ?? 0 }},
                    {{ $businessTypes[3] ?? 0 }}
                ],
                backgroundColor: ['#007bff', '#ffc107', '#6c757d'],
                borderRadius: 3,
                barPercentage: 0.5
            }]
        },
        options: chartOptionsSmall
    });

    // Productos - Barras horizontales (ya corregido)
    new Chart(document.getElementById('productsChart'), {
        type: 'bar',
        data: {
            labels: {!! json_encode($labelsProducts) !!},
            datasets: [{
                label: 'Productos',
                data: {!! json_encode($dataProducts) !!},
                backgroundColor: '#6610f2',
                borderRadius: 3
            }]
        },
        options: {
            ...chartOptionsSmall,
            indexAxis: 'y'
        }
    });
</script>
@endsection

