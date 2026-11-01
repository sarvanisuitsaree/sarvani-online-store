-- Sarvani Suit & Saree – database setup. Run ONCE in Supabase → SQL Editor → New query → Run.

-- 1. Who is allowed to manage the site
create table if not exists public.admins (
  user_id uuid primary key references auth.users(id) on delete cascade
);
alter table public.admins enable row level security;
drop policy if exists "admin can see own row" on public.admins;
create policy "admin can see own row" on public.admins for select to authenticated using (user_id = auth.uid());

create or replace function public.is_admin() returns boolean
language sql security definer set search_path = public stable as $$
  select exists (select 1 from public.admins where user_id = auth.uid());
$$;

-- 2. Products
create table if not exists public.products (
  id          text primary key,
  name        text not null check (char_length(name) between 1 and 120),
  category    text not null default 'Suits' check (category in ('Suits','Sarees','Blouses','Fabrics','Other')),
  price       integer check (price is null or price >= 0),
  sizes       jsonb not null default '[]'::jsonb,
  description text not null default '',
  images      jsonb not null default '[]'::jsonb,
  in_stock    boolean not null default true,
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now()
);
alter table public.products enable row level security;
drop policy if exists "anyone can view products" on public.products;
drop policy if exists "admin manages products" on public.products;
create policy "anyone can view products" on public.products for select to anon, authenticated using (true);
create policy "admin manages products" on public.products for all to authenticated using (public.is_admin()) with check (public.is_admin());

-- 3. Shop settings (always exactly one row)
create table if not exists public.settings (
  id        integer primary key check (id = 1),
  whatsapp  text not null default '917976900021',
  tagline   text not null default '',
  about     text not null default '',
  address   text not null default '',
  hours     text not null default '',
  instagram text not null default ''
);
alter table public.settings enable row level security;
drop policy if exists "anyone can view settings" on public.settings;
drop policy if exists "admin manages settings" on public.settings;
create policy "anyone can view settings" on public.settings for select to anon, authenticated using (true);
create policy "admin manages settings" on public.settings for all to authenticated using (public.is_admin()) with check (public.is_admin());

insert into public.settings (id, whatsapp, tagline, about, address, hours, instagram) values (
  1, '917976900021',
  'Timeless Indian elegance, chosen with love for every occasion.',
  'Established in 2015 in Murlipura, Jaipur, Sarvani Suit & Saree brings you traditional and designer ethnic wear: sarees, suits, lehariya prints, cotton and silk fabrics, and blouses for every occasion.',
  'Plot No. 9, Road Number 2, opposite VKI (Vishwakarma Industrial Area), Ram Nagar, Murlipura, Jaipur, Rajasthan 302039',
  '', 'https://instagram.com/sarvani_jaipur'
) on conflict (id) do nothing;

-- 4. Photo storage (public to view, only the admin can add/remove)
insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values ('product-images', 'product-images', true, 5242880, array['image/jpeg','image/png','image/webp'])
on conflict (id) do update set public = true, file_size_limit = 5242880, allowed_mime_types = array['image/jpeg','image/png','image/webp'];

drop policy if exists "admin uploads photos" on storage.objects;
drop policy if exists "admin updates photos" on storage.objects;
drop policy if exists "admin deletes photos" on storage.objects;
create policy "admin uploads photos" on storage.objects for insert to authenticated with check (bucket_id = 'product-images' and public.is_admin());
create policy "admin updates photos" on storage.objects for update to authenticated using (bucket_id = 'product-images' and public.is_admin());
create policy "admin deletes photos" on storage.objects for delete to authenticated using (bucket_id = 'product-images' and public.is_admin());
