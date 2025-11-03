@extends('layouts.sidebar')

@section('content')

  <h1>Associated Links for: {{ $link->title }}</h1>

  @if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
  @endif

  <form method="POST" action="{{ route('links.associations.update', $link->id) }}">
    @csrf
    @method('PUT')

    <div class="mb-3">
      <label for="associated_links" class="form-label">Associated Links</label>
      <select id="associated_links" name="associated_links[]" class="form-select" multiple>
        @foreach($allLinks as $l)
          @if($l->id !== $link->id)
            <option value="{{ $l->id }}"
              @selected(in_array($l->id, old('associated_links', $selectedAssociated ?? [])))>
              {{ $l->title }}
            </option>
          @endif
        @endforeach
      </select>
      <div class="form-text">These will show as small icons next to the primary button.</div>
      @error('associated_links') <div class="text-danger small">{{ $message }}</div> @enderror
    </div>

    <button type="submit" class="btn btn-primary">Save</button>
    <a class="btn btn-outline-secondary ms-2" href="{{ url('dashboard') }}">Back</a>
  </form>
@endsection

@push('styles')
  {{-- Optional: nicer multi-select UI --}}
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select/dist/css/tom-select.bootstrap5.min.css">
@endpush
@push('scripts')
  <script src="https://cdn.jsdelivr.net/npm/tom-select/dist/js/tom-select.complete.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      new TomSelect('#associated_links', {
        plugins: ['remove_button'],
        hideSelected: true,
        maxItems: null,
        create: false,
        persist: false
      });
    });
  </script>
@endpush
