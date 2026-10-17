@extends('layouts.app')

@section('content')
  <div class="bulletin-archive">
    <h1>Farské oznamy</h1>

    @if(count($bulletins))
      <ul class="bulletin-list">
        @foreach($bulletins as $bulletin)
          <li>
            <a href="{{ $bulletin->url() }}">
              <span class="bulletin-list-date">{{ $bulletin->formattedDate() }}</span>
              <span class="bulletin-list-title">{{ $bulletin->title }}</span>
            </a>
          </li>
        @endforeach
      </ul>
    @else
      <p>Zatiaľ tu nie sú žiadne oznamy.</p>
    @endif
  </div>
@endsection
