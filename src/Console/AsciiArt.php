<?php

namespace Daugt\Access\Console;


class AsciiArt {
    public function __invoke() {
        return PHP_EOL.'<fg=#10B981;options=bold>
                             xxxxxxxxxxx
                            xxxxxxxxxxxx
                           xxxxxxxxxxxxx
                           xxxxxxxxxxxx
                          xxxxxxxxxxxxx
                          xxxxxxxxxxxx
                         xxxxxxxxxxxxx
       xxxxxxxxxxxxxx    xxxxxxxxxxxxx
    xxxxxxxxxxxxxxxxxx   xxxxxxxxxxxx                 █████                                █████
    xxxxxxxxxxxxxxxxx   xxxxxxxxxxxxx                ░░███                                ░░███
   xxxxxxxxxxxxxxxxxx   xxxxxxxxxxxx               ███████   ██████   █████ ████  ███████ ███████       ██████   ██████  █████████████
  xxxxxxxxxxxxxxxxxx    xxxxxxxxxxxx              ███░░███  ░░░░░███ ░░███ ░███  ███░░███░░░███░       ███░░███ ███░░███░░███░░███░░███
 xxxxxxxxxxxxxxxxxxx    xxxxxxxxxxxx             ░███ ░███   ███████  ░███ ░███ ░███ ░███  ░███       ░███ ░░░ ░███ ░███ ░███ ░███ ░███
 xxxxxxxxxxxxxxxxxxx   xxxxxxxxxxxxx             ░███ ░███  ███░░███  ░███ ░███ ░███ ░███  ░███ ███   ░███  ███░███ ░███ ░███ ░███ ░███
xxxxxxxxxxxxxxxxxxx    xxxxxxxxxxxx              ░░████████░░████████ ░░████████░░███████  ░░█████  ██░░██████ ░░██████  █████░███ █████
xxxxxxxxxxxxxxxxxxx   xxxxxxxxxxxxx               ░░░░░░░░  ░░░░░░░░   ░░░░░░░░  ░░░░░███   ░░░░░  ░░  ░░░░░░   ░░░░░░  ░░░░░ ░░░ ░░░░░
xxxxxxxxxxxxxxxxx     xxxxxxxxxxxx                                               ███ ░███
xxxxxxxxxxxxxxxx     xxxxxxxxxxxxx                                              ░░██████
xxxxxxxxx         xxxxxxxxxxxxxxxx                                               ░░░░░░
xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
 xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
  xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
    xxxxxxxxxxxxxxxxxxxxxxxx
        xxxxxxxxxxxxxx


                </>'.PHP_EOL;
    }
}
