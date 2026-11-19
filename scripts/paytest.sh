# 支払方法の試験: $1=商品ID（複数可）。カートに入れ、お客様情報を入れて、お支払い画面の選択肢を見る（注文はしない）
J=cj_$$.txt; B=https://www.exdirect.net; rm -f $J
for id in "$@"; do
  T=$(curl -s -c $J -b $J $B/product/$id | grep -o 'name="csrf" value="[0-9a-f]*"' | head -1 | grep -o '[0-9a-f]\{32\}')
  curl -s -c $J -b $J -o /dev/null -X POST $B/cart --data-urlencode "add=$id" --data-urlencode "qty=1" --data-urlencode "csrf=$T"
done
P=$(curl -s -c $J -b $J $B/cart/customer); T=$(echo "$P" | grep -o 'name="csrf" value="[0-9a-f]*"' | head -1 | grep -o '[0-9a-f]\{32\}')
F=$(echo "$P" | grep -o 'name="[a-z_0-9]*"' | sort -u | sed 's/name="//;s/"//' | tr '\n' ' ')
ARGS="--data-urlencode csrf=$T"
for f in $F; do case $f in csrf) ;; email|email2) ARGS="$ARGS --data-urlencode $f=test@example.com";; zip) ARGS="$ARGS --data-urlencode zip=460-0008";; pref) ARGS="$ARGS --data-urlencode pref=愛知県";; tel) ARGS="$ARGS --data-urlencode tel=052-000-0000";; dm) ARGS="$ARGS --data-urlencode dm=0";; *) ARGS="$ARGS --data-urlencode $f=テスト";; esac; done
curl -s -c $J -b $J -o /dev/null -X POST $B/cart/customer $ARGS
D=$(curl -s -c $J -b $J $B/cart/delivery)
echo "$D" | grep -o 'name="payment" value="[a-z]*"[^>]*>' ; echo "$D" | grep -o '次の商品は[^<]*<b>[^<]*</b>[^<]*<br>[^<]*' ; echo "$D" | grep -c "Fatal\|Warning:"
# カード払いを無理に送ると、確認画面は銀行振込に直るか
T=$(echo "$D" | grep -o 'name="csrf" value="[0-9a-f]*"' | head -1 | grep -o '[0-9a-f]\{32\}')
curl -s -c $J -b $J -o /dev/null -X POST $B/cart/delivery --data-urlencode csrf=$T --data-urlencode ship_to=same --data-urlencode payment=stripe
curl -s -c $J -b $J $B/cart/confirm | grep -o '<th>お支払い方法</th><td>[^<]*' | head -1
rm -f $J
