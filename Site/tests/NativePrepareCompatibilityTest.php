<?php

declare(strict_types=1);

namespace Pericles\Tests;

use PHPUnit\Framework\TestCase;

final class NativePrepareCompatibilityTest extends TestCase
{
    public function testPreparedSqlDoesNotReuseNamedPlaceholders(): void
    {
        $failures=[];
        foreach($this->phpFiles() as $file){
            $tokens=token_get_all((string)file_get_contents($file));$count=count($tokens);
            for($i=0;$i<$count;$i++){
                if(!is_array($tokens[$i])||$tokens[$i][0]!==T_STRING||strtolower($tokens[$i][1])!=='prepare')continue;
                $line=(int)$tokens[$i][2];$cursor=$i+1;
                while($cursor<$count&&$tokens[$cursor]!=='(')$cursor++;
                if($cursor===$count)continue;
                $depth=0;$expression='';
                for(;$cursor<$count;$cursor++){
                    $text=is_array($tokens[$cursor])?$tokens[$cursor][1]:$tokens[$cursor];
                    if($text==='(')$depth++;
                    if($text===')'){$depth--;if($depth===0)break;}
                    $expression.=$text;
                }
                $duplicates=$this->duplicatePlaceholders($expression);
                if($duplicates!==[])$failures[]=str_replace('\\','/',$file).':'.$line.' '.implode(', ',$duplicates);
            }
            foreach($tokens as $token){
                if(!is_array($token)||$token[0]!==T_CONSTANT_ENCAPSED_STRING||!preg_match('/\b(?:INSERT\s+INTO|UPDATE\s+[a-z_]|DELETE\s+FROM|SELECT\s+)/i',$token[1]))continue;
                $duplicates=$this->duplicatePlaceholders($token[1]);
                if($duplicates!==[])$failures[]=str_replace('\\','/',$file).':'.$token[2].' '.implode(', ',$duplicates);
            }
        }
        self::assertSame([],array_values(array_unique($failures)),"PDO MySQL native prepares require each named placeholder to be unique within a statement.\n".implode("\n",$failures));
    }

    private function duplicatePlaceholders(string $source): array
    {
        preg_match_all('/(?<!:):[a-z_][a-z0-9_]*/i',$source,$matches);
        return array_keys(array_filter(array_count_values($matches[0]),static fn(int $count):bool=>$count>1));
    }

    private function phpFiles(): array
    {
        $files=[];
        foreach(['src','public','bin','tools'] as $directory){
            $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__).'/'.$directory,\FilesystemIterator::SKIP_DOTS));
            foreach($iterator as $file)if($file->isFile()&&$file->getExtension()==='php')$files[]=$file->getPathname();
        }
        return $files;
    }
}
