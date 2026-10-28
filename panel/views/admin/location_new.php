<div class="page-head"><div><h1>New location</h1><p class="muted">Group nodes by region or datacenter.</p></div><a class="btn ghost" href="/admin/locations">Cancel</a></div>
<form class="card" method="post" action="/admin/locations">
  <?= csrf_field() ?>
  <div class="grid three">
    <label>Short code <input name="short" required maxlength="60" placeholder="eu-de-1"></label>
    <label>Description <input name="description" maxlength="191" placeholder="Frankfurt, Germany"></label>
    <div class="end"><button class="btn" type="submit">Add location</button></div>
  </div>
</form>
