@extends('adminlte::page')

@section('title','Crear Categoría')

@section('content_header')
<h1>Crear Categoría de Negocio</h1>
@stop

@section('content')
<form action="{{ route('admin.category-business.store') }}" method="POST" enctype="multipart/form-data">
    @csrf
    @include('admin.category_business.partials.forms')
</form>
@stop
