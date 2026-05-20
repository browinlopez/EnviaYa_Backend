@extends('adminlte::page')

@section('title', 'Reporte Financiero')

@section('content_header')
<h1>Reporte Financiero</h1>
@stop

@section('content')
    <div class="container-fluid">
        {{-- Filtros arriba, en una fila ordenada --}}
        <form method="GET" action="{{ route('admin.report.general') }}" class="form-row mb-4">
            {{-- filtros --}}
            <div class="col-md-3">
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
            <div class="col-md-3">
                <label class="form-label">Domiciliario</label>
                <select name="domiciliary_id" class="form-control">
                    <option value="">Todos</option>
                    @foreach ($domiciliaries as $d)
                        <option value="{{ $d->domiciliary_id }}" {{ $domiciliary_id == $d->domiciliary_id ? 'selected' : '' }}>
                            {{ $d->user->name ?? 'Sin nombre' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Desde</label>
                <input type="date" name="date_start" value="{{ $date_start->toDateString() }}" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label">Hasta</label>
                <input type="date" name="date_end" value="{{ $date_end->toDateString() }}" class="form-control">
            </div>

            <div class="col-md-1 d-flex align-items-end">
                <button type="submit" class="btn btn-primary btn-block">Filtrar</button>
            </div>

            <div class="col-md-1 d-flex align-items-end">
                <a href="{{ route('admin.report.export', [
        'business_id' => request('business_id'),
        'domiciliary_id' => request('domiciliary_id'),
        'date_start' => request('date_start'),
        'date_end' => request('date_end'),
    ]) }}" class="btn btn-success btn-block">Exportar</a>
            </div>
        </form>


        {{-- Tabs para los charts --}}
        <ul class="nav nav-tabs" id="chartTabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" id="incomeBusiness-tab" data-toggle="tab" href="#incomeBusiness"
                    role="tab">Ingresos negocio</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="incomeDomiciliary-tab" data-toggle="tab" href="#incomeDomiciliary" role="tab">Pagos
                    a domiciliarios</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="paymentsToStore-tab" data-toggle="tab" href="#paymentsToStore" role="tab">Pagos
                    a tenderos</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="profitPerOrder-tab" data-toggle="tab" href="#profitPerOrder" role="tab">Rentabilidad
                    por pedido</a>
            </li>
        </ul>

        <div class="tab-content mt-3">
            <div class="tab-pane fade show active" id="incomeBusiness" role="tabpanel">
                <canvas id="incomeBusinessChart" style="max-height:250px;"></canvas>
            </div>
            <div class="tab-pane fade" id="incomeDomiciliary" role="tabpanel">
                <canvas id="incomeDomiciliaryChart" style="max-height:250px;"></canvas>
            </div>
            <div class="tab-pane fade" id="paymentsToStore" role="tabpanel">
                <canvas id="paymentsToStoreChart" style="max-height:250px;"></canvas>
            </div>
            <div class="tab-pane fade" id="profitPerOrder" role="tabpanel">
                <canvas id="profitPerOrderChart" style="max-height:250px;"></canvas>
            </div>
        </div>
    </div>
@endsection

@section('js')
    {{-- Chart.js --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        new Chart(document.getElementById('incomeBusinessChart'), {
            type: 'bar',
            data: {
                labels: @json($incomeBusiness->pluck('date')),
                datasets: [{
                    label: 'Ingresos negocio',
                    data: @json($incomeBusiness->pluck('total_income')),
                    backgroundColor: 'rgba(54, 162, 235, 0.5)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });

        new Chart(document.getElementById('incomeDomiciliaryChart'), {
            type: 'bar',
            data: {
                labels: @json($incomeDomiciliary->pluck('date')),
                datasets: [{
                    label: 'Pagos a domiciliarios',
                    data: @json($incomeDomiciliary->pluck('total_domicilio')),
                    backgroundColor: 'rgba(255, 99, 132, 0.5)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });

        new Chart(document.getElementById('paymentsToStoreChart'), {
            type: 'bar',
            data: {
                labels: @json($paymentsToStore->pluck('date')),
                datasets: [{
                    label: 'Pagos a tenderos',
                    data: @json($paymentsToStore->pluck('total_tendero')),
                    backgroundColor: 'rgba(75, 192, 192, 0.5)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });

        new Chart(document.getElementById('profitPerOrderChart'), {
            type: 'bar',
            data: {
                labels: @json($profitPerOrder->pluck('order_sale_id')),
                datasets: [{
                    label: 'Rentabilidad por pedido',
                    data: @json($profitPerOrder->pluck('profit')),
                    backgroundColor: 'rgba(153, 102, 255, 0.5)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    </script>
@endsection