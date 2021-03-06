<?xml version="1.0" encoding="utf-8"?>
<xsl:stylesheet
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"  

    version="1.0">    
    
    <!--<xsl:template match="component[@class='BlogPost']">
        <xsl:if test="not(recordset[@empty])">
            <div class="vacancies">
                <xsl:apply-templates/>
            </div>
        </xsl:if>
    </xsl:template>-->
    
    <xsl:template match="recordset[parent::component[@class='BlogPost'][@type='list']]">
        <ul class="bbbbb_list">
            <xsl:if test="../@curr_user_id">
                <a href="{$BASE}{$LANG_ABBR}blogs/post/create/">New post</a>
            </xsl:if>
            <xsl:apply-templates/>
        </ul>
    </xsl:template>
    
    <xsl:template match="record[ancestor::component[@class='BlogPost'][@type='list']]">
        <li class="blog_item">
        	<span><xsl:value-of select="field[@name='post_created']"/></span>
			<a href="{$BASE}{$LANG_ABBR}blogs/blog/{field[@name='blog_id']}">
				<xsl:if test="field[@name='u_nick'] != ''">
				    <xsl:value-of select="field[@name='u_nick']"/>
				</xsl:if>
				<xsl:if test="field[@name='u_nick'] = ''">
				    <xsl:value-of select="field[@name='u_fullname']"/>
				</xsl:if>
			</a>
			
        	<h4>
        		<a href="{$BASE}{$LANG_ABBR}blogs/post/{field[@name='post_id']}">
                   <xsl:value-of select="field[@name='post_name']"/>
                </a>
        	</h4>
            <p><xsl:value-of select="field[@name='post_text_rtf']" disable-output-escaping="yes"/></p>
            
            <span>Комментариев  
			    <xsl:value-of select="field[@name='comments_num']"/>
			    <xsl:if test="field[@name='comments_num'] = ''">0</xsl:if>
			</span>
			<xsl:if test="(../../@curr_user_id = field[@name='u_id']) or  ../../@curr_user_is_admin">
				<a href="{$BASE}{$LANG_ABBR}blogs/post/{field[@name='post_id']}/edit/">Редактировать</a>
			</xsl:if>
			</li>
    </xsl:template>
    
    <xsl:template match="record[ancestor::component[@class='BlogPost'][@componentAction='view']]">
       	<span><xsl:value-of select="field[@name='post_created']"/></span>
		<a href="{$BASE}{$LANG_ABBR}blogs/blog/{field[@name='blog_id']}">
			<xsl:if test="field[@name='u_nick'] != ''">
			    <xsl:value-of select="field[@name='u_nick']"/>
			</xsl:if>
			<xsl:if test="field[@name='u_nick'] = ''">
			    <xsl:value-of select="field[@name='u_fullname']"/>
			</xsl:if>
		</a>
		
       	<h4>
               <xsl:value-of select="field[@name='post_name']"/>
       	</h4>
        <xsl:if test="(../../@curr_user_id = field[@name='u_id']) or  ../../@curr_user_is_admin">
            <a href="{$BASE}{$LANG_ABBR}blogs/post/{field[@name='post_id']}/edit/">Редактировать</a>
        </xsl:if>
        <p><xsl:value-of select="field[@name='post_text_rtf']" disable-output-escaping="yes"/></p>
        
        <div class="blog_comments">
                <xsl:if test="field[@name='comments'] != ''">
                <xsl:apply-templates select="field[@name='comments']"/>
            </xsl:if>
            <xsl:if test="field[@name='comments'] = ''">
                <div class="comments" style="display:none">
                        Комментарии (<span></span>)
                        <ul/>
                </div>
            </xsl:if>
        </div>
    </xsl:template>

<!--    <xsl:template match="record[ancestor::component[@class='BlogPost'][@componentAction='edit']]">
        <form state="{$BASE}{$LANG_ABBR}blogs/post/{@post_id}/save/" method="post" class="form">
            <xsl:apply-templates/>
        </form>

    </xsl:template>-->

</xsl:stylesheet>
