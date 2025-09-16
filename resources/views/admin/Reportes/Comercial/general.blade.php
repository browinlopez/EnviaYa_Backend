@extends('adminlte::page')

@section('title', 'Dashboard')

@section('content_header')
<h1>Indicadores</h1>
@stop

@section('content')
<form method="GET" class="form-inline mb-3">
  <input type="date" name="date_start" value="{{ $start->toDateString() }}" class="form-control mr-2">
  <input type="date" name="date_end" value="{{ $end->toDateString() }}" class="form-control mr-2">
  <button class="btn btn-primary mr-2">Filtrar</button>
  <a href="{{ route('admin.reportes.comerciales.export', ['date_start'=>$start->toDateString(), 'date_end'=>$end->toDateString()]) }}" class="btn btn-success">
    Exportar Excel
  </a>
</form>

<div class="row mb-3">
  <div class="col-md-3">
    <div class="card card-body text-center">
      <h6 class="mb-0">Ticket promedio</h6>
      <p class="mb-0">${{ number_format($avgTicket,0) }}</p>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card card-body text-center">
      <h6 class="mb-0">Usuarios nuevos</h6>
      <p class="mb-0">{{ $newUsers }}</p>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card card-body text-center">
      <h6 class="mb-0">Usuarios recurrentes</h6>
      <p class="mb-0">{{ $recurrentUsers }}</p>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card card-body text-center">
      <h6 class="mb-0">Tasa repetición</h6>
      <p class="mb-0">{{ $repeatRate }}%</p>
    </div>
  </div>
</div>

{{-- Nav tabs --}}
<ul class="nav nav-tabs" id="chartTabs" role="tablist">
  <li class="nav-item">
    <a class="nav-link active" id="orders-tab" data-toggle="tab" href="#orders" role="tab">Pedidos por día</a>
  </li>
  <li class="nav-item">
    <a class="nav-link" id="businesses-tab" data-toggle="tab" href="#businesses" role="tab">Top Tiendas</a>
  </li>
  <li class="nav-item">
    <a class="nav-link" id="categories-tab" data-toggle="tab" href="#categories" role="tab">Top Categorías</a>
  </li>
</ul>

{{-- Tab panes --}}
<div class="tab-content p-3 border border-top-0" id="chartTabsContent">
  <div class="tab-pane fade show active" id="orders" role="tabpanel">
    <canvas id="ordersByDay" style="max-height:200px"></canvas>
  </div>
  <div class="tab-pane fade" id="businesses" role="tabpanel">
    <canvas id="topBusinesses" style="max-height:200px"></canvas>
  </div>
  <div class="tab-pane fade" id="categories" role="tabpanel">
    <canvas id="topCategories" style="max-height:200px"></canvas>
  </div>
</div>
@endsection

@section('js')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
new Chart(document.getElementById('ordersByDay'),{
  type:'line',
  data:{
    labels: @json($ordersByDay->pluck('date')),
    datasets:[{
      label:'Pedidos por día',
      data:@json($ordersByDay->pluck('total')),
      borderColor:'blue',
      fill:false
    }]
  },
  options:{responsive:true,maintainAspectRatio:false}
});

new Chart(document.getElementById('topBusinesses'),{
  type:'bar',
  data:{
    labels: @json($topBusinesses->pluck('business.name')),
    datasets:[{
      label:'Pedidos',
      data:@json($topBusinesses->pluck('total_orders')),
      backgroundColor:'rgba(54,162,235,0.5)'
    }]
  },
  options:{responsive:true,maintainAspectRatio:false}
});

new Chart(document.getElementById('topCategories'),{
  type:'bar',
  data:{
    labels: @json($topCategories->pluck('name')),
    datasets:[{
      label:'Items vendidos',
      data:@json($topCategories->pluck('total_items')),
      backgroundColor:'rgba(255,99,132,0.5)'
    }]
  },
  options:{responsive:true,maintainAspectRatio:false}
});
</script>
@endsection
