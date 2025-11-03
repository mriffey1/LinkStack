     <ul class="pagination justify-content-center">
                                  {!! $links ?? ''->links() !!}
                            </ul>
                
                <a class="btn btn-primary" href="{{ url('/admin/users/all') }}"><i class="bi bi-arrow-left-short"></i> {{__('messages.Back')}}</a>