<article @php(post_class('bulletin'))>
  <div class="bulletin-single">
    <a href="{{ get_post_type_archive_link('bulletin') }}" class="back-btn"><span>&larr;</span> Všetky oznamy</a>

    <header class="bulletin-header">
      <h1 class="entry-title">{!! get_the_title() !!}</h1>
      @if($bulletin && $bulletin->formattedDate())
        <p class="bulletin-date">{{ $bulletin->formattedDate() }}</p>
      @endif
    </header>

    @php($liturgy = $bulletin ? $bulletin->liturgyRows() : [])
    @if(count($liturgy))
      <section class="bulletin-liturgy">
        <h2>Liturgia týždňa</h2>
        <table class="bulletin-liturgy-table">
          <tbody>
            @foreach($liturgy as $row)
              <tr>
                <th>{{ trim(($row['weekday'] ? $row['weekday'] . ', ' : '') . $row['date']) }}</th>
                <td>{{ $row['text'] }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </section>
    @endif

    <section class="bulletin-items">
      @foreach($bulletin ? $bulletin->items : [] as $item)
        @if(empty($item['label']) && trim(strip_tags($item['text'] ?? '')) === '')
          @continue
        @endif
        <div class="bulletin-item">
          @if(!empty($item['label']))<strong class="bulletin-item-label">{{ $item['label'] }}</strong> — @endif<span>{!! $item['text'] ?? '' !!}</span>
        </div>
      @endforeach
    </section>

    <div class="bulletin-donate-intro">
      <strong class="bulletin-item-label">Podpor nás</strong> — <span>Tieto oznamy, naša farnosť aj celé Saleziánske dielo na Trnávke môžu fungovať vďaka darom. Pridaj sa k pravidelným darcom ľubovoľným príspevkom každý mesiac. Ďakujeme.</span>
    </div>
    @if(!empty($form))
      <div class="embedded-dajnato-form-holder">{!! $form !!}</div>
    @endif

    <div class="thank-you">
      <div>Ďakujeme</div>
    </div>
  </div>
</article>
