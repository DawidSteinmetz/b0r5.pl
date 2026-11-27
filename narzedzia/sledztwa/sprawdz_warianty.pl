#!/usr/bin/perl
# Sprawdza spójność wariantów faz w api/migracje/084_sledztwa_warianty.sql.
# Użycie: perl narzedzia/sledztwa/sprawdz_warianty.pl [plik.sql] [liczba_losowan]
# Sprawdza: 12 × 12 wierszy; wstawki {Z}{S}{W} mają tag postaci; każdy tag z „wymaga” ktoś daje; każda faza ma
# wariant bez wymagań dla jawnej i ukrytej; symulacja generatora (kolejność 4–10 losowa, jawność fazy losowa,
# k12 spośród zgodnych) — czy nigdy nie utyka i czy każdy wariant da się wylosować.
use strict;
use warnings;
use utf8;
use open qw(:std :encoding(UTF-8));

my $plik = $ARGV[0] // 'api/migracje/084_sledztwa_warianty.sql';
my $proby = $ARGV[1] // 100000;
open my $fh, '<:encoding(UTF-8)', $plik or die "Brak pliku $plik\n";
my $sql = do { local $/; <$fh> };

my $str = qr/'((?:[^']|'')*)'/;
my @w;
while ($sql =~ /^\((\d+),(\d+),$str,$str,$str,$str,$str\)[,\s]*$/mg) {
  my %r = (faza => $1, nr => $2, nazwa => $3, szkic => $4, jawnosc => $7);
  $r{wymaga} = [grep { length } split /,/, $5];
  $r{daje} = [grep { length } split /,/, $6];
  push @w, \%r;
}

my @bledy;
my %ile;
$ile{$_->{faza}}{$_->{nr}}++ for @w;
for my $f (1 .. 12) {
  for my $n (1 .. 12) {
    my $k = $ile{$f}{$n} // 0;
    push @bledy, "faza $f wariant $n: $k wierszy (ma być 1)" if $k != 1;
  }
}

my %postac = (Z => 'zleceniodawca', S => 'sledzacy', W => 'swiadek');
my (%dawane_przez, %wymagane_przez);
for my $r (@w) {
  my %tagi = map { $_ => 1 } @{$r->{wymaga}}, @{$r->{daje}};
  for my $p (keys %postac) {
    push @bledy, "$r->{faza}.$r->{nr} „$r->{nazwa}”: wstawka {$p} bez tagu $postac{$p}"
      if $r->{szkic} =~ /\{$p\}/ && !$tagi{$postac{$p}};
  }
  for my $x ($r->{szkic} =~ /\{([^}]*)\}/g) {
    push @bledy, "$r->{faza}.$r->{nr}: nieznana wstawka {$x}" unless $x =~ /^[ABZSWM]$/;
  }
  push @bledy, "$r->{faza}.$r->{nr}: nazwa dłuższa niż 120 znaków" if length $r->{nazwa} > 120;
  push @bledy, "$r->{faza}.$r->{nr}: zła jawność $r->{jawnosc}" unless $r->{jawnosc} =~ /^(dowolna|jawna|ukryta)$/;
  push @{$dawane_przez{$_}}, $r->{faza} for @{$r->{daje}};
  push @{$wymagane_przez{$_}}, "$r->{faza}.$r->{nr}" for @{$r->{wymaga}};
}
for my $t (sort keys %wymagane_przez) {
  push @bledy, "tag $t wymagany (@{$wymagane_przez{$t}}), ale nikt go nie daje" unless $dawane_przez{$t};
}
for my $f (1 .. 12) {
  for my $j (qw(jawna ukryta)) {
    my $n = grep { $_->{faza} == $f && !@{$_->{wymaga}} && ($_->{jawnosc} eq 'dowolna' || $_->{jawnosc} eq $j) } @w;
    push @bledy, "faza $f: brak wariantu bez wymagań dla jawności $j" unless $n;
  }
}

# Symulacja generatora.
my (%trafienia, $utkniecia);
srand(84);
for (1 .. $proby) {
  my @przest = (4 .. 10);
  for (my $i = $#przest; $i > 0; $i--) { my $j = int rand($i + 1); @przest[$i, $j] = @przest[$j, $i]; }
  my %tagi;
  for my $f (1, 2, 3, @przest, 11, 12) {
    my $jaw = rand() < 0.5 ? 'jawna' : 'ukryta';
    my @ok = grep {
      my $r = $_;
      $r->{faza} == $f && ($r->{jawnosc} eq 'dowolna' || $r->{jawnosc} eq $jaw)
        && !grep { !$tagi{$_} } @{$r->{wymaga}};
    } @w;
    unless (@ok) { $utkniecia++; last; }
    my $r = $ok[int rand @ok];
    $trafienia{"$r->{faza}.$r->{nr}"}++;
    $tagi{$_} = 1 for @{$r->{daje}};
  }
}
for my $r (@w) {
  my $k = "$r->{faza}.$r->{nr}";
  push @bledy, "$k „$r->{nazwa}”: ani razu nie wylosowany w $proby próbach" unless $trafienia{$k};
}
push @bledy, "generator utknął $utkniecia razy na $proby" if $utkniecia;

printf "Wierszy: %d\n", scalar @w;
print "Tagi (ile wariantów daje / wymaga):\n";
my %wszystkie = map { $_ => 1 } keys %dawane_przez, keys %wymagane_przez;
printf "  %-20s %3d / %3d\n", $_, scalar @{$dawane_przez{$_} // []}, scalar @{$wymagane_przez{$_} // []}
  for sort keys %wszystkie;
print "Najrzadsze warianty (% losowań w swojej fazie):\n";
my @rzadkie = sort { ($trafienia{$a} // 0) <=> ($trafienia{$b} // 0) } map { "$_->{faza}.$_->{nr}" } @w;
printf "  %-6s %5.1f%%\n", $_, 100 * ($trafienia{$_} // 0) / $proby for @rzadkie[0 .. 7];
if (@bledy) { print "BŁĘDY:\n", map { "  $_\n" } @bledy; exit 1; }
print "OK\n";
